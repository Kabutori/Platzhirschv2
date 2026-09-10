<?php
namespace App\Modules\Widget\Http;
use App\Contracts\Module\{TenantRuntime, AuditSink};
use App\Modules\Reservation\PublicApi\ReservationGateway as ReservationService;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
class WidgetController
{
    public function __construct(
        private DatabaseManager $db,
        private TenantRuntime $runtime,
        private AuditSink $audit,
        private ReservationService $reservations,
    ) {}
    public function list(Request $r)
    {
        abort_unless($r->user()->hasPermission('widget.manage'), 403);
        return $this->db
            ->table('widget_clients')
            ->where('tenant_id', $r->attributes->get('tenant')->id)
            ->get([
                'id',
                'origins',
                'expires_at',
                'created_at',
                'duration_minutes',
                'accent',
                'language',
                'position',
                'max_party_size',
                'show_brand',
            ]);
    }
    public function create(Request $r)
    {
        abort_unless($r->user()->hasPermission('widget.manage'), 403);
        $data = $r->validate([
            'origins' => 'required|array|min:1|max:10',
            'origins.*' => 'required|url:http,https|max:250',
            'months' => 'sometimes|integer|min:1|max:12',
            'language' => 'sometimes|in:de,en',
            'position' => 'sometimes|in:inline,bottom-right,bottom-left,top-right,top-left',
            'max_party_size' => 'sometimes|integer|min:1|max:50',
            'show_brand' => 'sometimes|boolean',
            'duration_minutes' => 'sometimes|integer|min:30|max:240|multiple_of:15',
            'accent' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/D'],
        ]);
        $origins = [];
        foreach ($data['origins'] as $url) {
            $parts = parse_url($url);
            abort_if(
                isset($parts['user']) ||
                    isset($parts['pass']) ||
                    isset($parts['query']) ||
                    isset($parts['fragment']) ||
                    (!empty($parts['path']) && $parts['path'] !== '/'),
                422,
                'Nur Website-Ursprünge ohne Pfad angeben.',
            );
            $origins[] = rtrim(strtolower($url), '/');
        }
        $token = bin2hex(random_bytes(32));
        $id = $this->db->table('widget_clients')->insertGetId([
            'tenant_id' => $r->attributes->get('tenant')->id,
            'token_hash' => hash('sha256', $token),
            'origins' => json_encode($origins),
            'expires_at' => now()->addMonthsNoOverflow($data['months'] ?? 12),
            'duration_minutes' => $data['duration_minutes'] ?? 90,
            'accent' => $data['accent'] ?? null,
            'language' => $data['language'] ?? 'de',
            'position' => $data['position'] ?? 'inline',
            'max_party_size' => $data['max_party_size'] ?? 50,
            'show_brand' => $data['show_brand'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('widget.created', $id, $r->attributes->get('tenant')->id);
        return response()->json(
            [
                'id' => $id,
                'token' => $token,
                'url' => config('app.url') . '/admin/#booking?token=' . $token,
                'embed' =>
                    '<platzhirsch-booking token="' .
                    $token .
                    '"></platzhirsch-booking>' .
                    "\n" .
                    '<script src="' .
                    htmlspecialchars(rtrim(config('app.url'), '/') . '/widget.js', ENT_QUOTES, 'UTF-8') .
                    '" defer></script>',
            ],
            201,
        );
    }
    public function update(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('widget.manage'), 403);
        $query = $this->db
            ->table('widget_clients')
            ->where('tenant_id', $r->attributes->get('tenant')->id)
            ->where('id', $id);
        abort_unless($query->exists(), 404);
        $data = $r->validate([
            'duration_minutes' => 'required|integer|min:30|max:240|multiple_of:15',
            'accent' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'language' => 'required|in:de,en',
            'position' => 'required|in:inline,bottom-right,bottom-left,top-right,top-left',
            'max_party_size' => 'required|integer|min:1|max:50',
            'show_brand' => 'required|boolean',
        ]);
        $query->update([...$data, 'updated_at' => now()]);
        $this->audit->record('widget.updated', $id, $r->attributes->get('tenant')->id);
        return response()->json(['id' => $id, 'updated' => true]);
    }
    public function revoke(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('widget.manage'), 403);
        abort_unless(
            $this->db
                ->table('widget_clients')
                ->where('tenant_id', $r->attributes->get('tenant')->id)
                ->where('id', $id)
                ->delete(),
            404,
        );
        $this->audit->record('widget.revoked', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    private function run(Request $r, string $token, \Closure $callback)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $client = $this->db
            ->table('widget_clients')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($client, 404);
        $origin = $r->header('Origin');
        $allowed = [...json_decode($client->origins, true), rtrim(config('app.url'), '/')];
        abort_if($origin && !in_array($origin, $allowed, true), 403);
        try {
            $response = $this->runtime->withTenant(
                (int) $client->tenant_id,
                fn($tenant) => response()->json($callback($tenant, $client)),
            );
        } catch (\Throwable $error) {
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $handler->report($error);
            $response = $handler->render($r, $error);
        }
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        return $response;
    }

    public function config(Request $r, string $token)
    {
        return $this->run(
            $r,
            $token,
            fn($tenant, $client) => [
                'name' => $tenant->name,
                'timezone' => $tenant->timezone,
                'duration_minutes' => $client->duration_minutes,
                'accent' => $client->accent,
                'language' => $client->language,
                'position' => $client->position,
                'max_party_size' => $client->max_party_size,
                'show_brand' => (bool) $client->show_brand,
                ...$this->reservations->catalog(),
            ],
        );
    }
    public function options(Request $r, string $token)
    {
        return $this->run($r, $token, fn() => []);
    }
    public function availability(Request $r, string $token, ReservationService $service)
    {
        return $this->run($r, $token, function ($tenant, $client) use ($r, $service) {
            $data = $r->validate([
                'starts_at' => 'required|date_format:Y-m-d\\TH:i',
                'party_size' => 'required|integer|min:1|max:' . $client->max_party_size,
            ]);
            return [
                'tables' => $service->availableTables(
                    $data['starts_at'],
                    (int) $data['party_size'],
                    (int) $client->duration_minutes,
                    $tenant->timezone,
                ),
            ];
        });
    }
    public function book(Request $r, string $token, ReservationService $service)
    {
        return $this->run($r, $token, function ($tenant, $client) use ($r, $service) {
            $data = $r->validate([
                ...\App\Modules\Reservation\PublicApi\ReservationRules::rules(),
                'email' => 'required|email|max:254',
                'consent' => 'required|accepted',
                'party_size' => 'required|integer|min:1|max:' . $client->max_party_size,
                'website' => 'nullable|string|max:0',
                'duration_minutes' => 'sometimes|integer',
                'request_key' => 'required|uuid',
            ]);
            abort_if(
                !empty($data['additional_table_ids']),
                422,
                'Tischkombinationen bitte direkt mit dem Restaurant abstimmen.',
            );
            $data['status'] = 'confirmed';
            $data['duration_minutes'] = (int) $client->duration_minutes;
            $reservation = $service->save($data, $tenant->timezone, null, 'widget');
            $this->audit->record('widget.booked', $reservation->id, $tenant->id);
            return [
                'id' => $reservation->id,
                'message' => 'Reservierung gespeichert. Bitte notiere deine Buchungsnummer.',
            ];
        });
    }
}
