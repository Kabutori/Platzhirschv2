<?php
namespace App\Http\Controllers;
use App\Models\Tenant;
use App\Services\{TenantDatabase, ReservationService, Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class WidgetController
{
    public function list(Request $r)
    {
        abort_unless($r->user()->canManage(), 403);
        return DB::table('widget_clients')
            ->where('tenant_id', $r->attributes->get('tenant')->id)
            ->get(['id', 'origins', 'expires_at', 'created_at']);
    }
    public function create(Request $r)
    {
        abort_unless($r->user()->canManage(), 403);
        $data = $r->validate([
            'origins' => 'required|array|min:1|max:10',
            'origins.*' => 'required|url:http,https|max:250',
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
        $id = DB::table('widget_clients')->insertGetId([
            'tenant_id' => $r->attributes->get('tenant')->id,
            'token_hash' => hash('sha256', $token),
            'origins' => json_encode($origins),
            'expires_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Audit::record('widget.created', $id, $r->attributes->get('tenant')->id);
        return response()->json(
            ['id' => $id, 'token' => $token, 'url' => config('app.url') . '/admin/#booking?token=' . $token],
            201,
        );
    }
    public function revoke(Request $r, int $id)
    {
        abort_unless($r->user()->canManage(), 403);
        abort_unless(
            DB::table('widget_clients')
                ->where('tenant_id', $r->attributes->get('tenant')->id)
                ->where('id', $id)
                ->delete(),
            404,
        );
        Audit::record('widget.revoked', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    private function run(Request $r, string $token, \Closure $callback)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $client = DB::table('widget_clients')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($client, 404);
        $origin = $r->header('Origin');
        $allowed = [...json_decode($client->origins, true), rtrim(config('app.url'), '/')];
        abort_if($origin && !in_array($origin, $allowed, true), 403);
        $tenant = Tenant::findOrFail($client->tenant_id);
        abort_unless($tenant->status === 'active', 403);
        $database = app(TenantDatabase::class);
        $database->connect($tenant);
        try {
            $response = response()->json($callback($tenant));
            if ($origin) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
            }
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            return $response;
        } finally {
            $database->disconnect();
        }
    }
    public function config(Request $r, string $token)
    {
        return $this->run(
            $r,
            $token,
            fn($tenant) => [
                'name' => $tenant->name,
                'timezone' => $tenant->timezone,
                'tables' => DB::connection('tenant')
                    ->table('dining_tables')
                    ->where('active', true)
                    ->get(['id', 'name', 'capacity']),
                'hours' => DB::connection('tenant')
                    ->table('opening_hours')
                    ->get(['weekday', 'opens', 'closes']),
            ],
        );
    }
    public function options(Request $r, string $token)
    {
        return $this->run($r, $token, fn() => []);
    }
    public function book(Request $r, string $token, ReservationService $service)
    {
        return $this->run($r, $token, function ($tenant) use ($r, $service) {
            $data = $r->validate([
                ...RestaurantController::reservationRules(),
                'email' => 'required|email|max:254',
                'consent' => 'required|accepted',
                'website' => 'nullable|string|max:0',
            ]);
            $data['status'] = 'confirmed';
            $data['duration_minutes'] = 90;
            $reservation = $service->save($data, $tenant->timezone, null, 'widget');
            Audit::record('widget.booked', $reservation->id, $tenant->id);
            return [
                'id' => $reservation->id,
                'message' => 'Reservierung gespeichert. Bitte notiere deine Buchungsnummer.',
            ];
        });
    }
}
