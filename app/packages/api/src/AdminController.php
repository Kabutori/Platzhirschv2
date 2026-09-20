<?php
namespace App\Modules\Api;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use App\Contracts\Module\AuditSink;
class AdminController
{
    public function __construct(
        private DatabaseManager $db,
        private Catalog $catalog,
        private Hasher $hash,
        private AuditSink $audit,
    ) {}
    private function authorize(Request $r, bool $confirm = false): void
    {
        abort_unless($r->user()?->active && $r->user()->canManage(), 403);
        if ($confirm) {
            $r->validate(['password' => 'required|string', 'confirmed' => 'required|accepted']);
            abort_unless(
                $this->hash->check($r->input('password'), $r->user()->password),
                403,
                'Kennwort stimmt nicht.',
            );
        }
    }
    public function index(Request $r): array
    {
        $this->authorize($r);
        return [
            'tokens' => $this->db
                ->table('api_tokens')
                ->where('user_id', $r->user()->id)
                ->select(
                    'id',
                    'service_account_id',
                    'operations',
                    'rotated_to',
                    'name',
                    'scopes',
                    'audience',
                    'cidrs',
                    'expires_at',
                    'revoked_at',
                    'last_used_at',
                    'created_at',
                )
                ->latest('created_at')
                ->limit(100)
                ->get(),
            'service_accounts' => $this->db
                ->table('api_service_accounts')
                ->where('user_id', $r->user()->id)
                ->orderBy('name')
                ->get(),
            'operations' => array_map(
                fn($op) => [
                    'id' => $op['id'],
                    'scope' => $op['scope'],
                    'method' => $op['method'],
                    'path' => $op['path'],
                ],
                $this->catalog->manageable($r->user()),
            ),
            'scopes' => $this->catalog->scopes($r->user()),
            'modules' => [
                'mcp',
                ...array_values(array_unique(array_column($this->catalog->all(), 'module'))),
            ],
            'settings' => $r->user()->isSystem() ? $this->db->table('api_settings')->get() : [],
            'confirmations' => $this->db
                ->table('api_confirmations')
                ->where('user_id', $r->user()->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'events' => $this->db
                ->table('api_access_events')
                ->whereIn(
                    'token_id',
                    $this->db->table('api_tokens')->where('user_id', $r->user()->id)->select('id'),
                )
                ->latest('id')
                ->limit(100)
                ->get(),
        ];
    }
    public function create(Request $r): array
    {
        $this->authorize($r, true);
        $d = $r->validate([
            'service_account_id' => 'nullable|uuid',
            'operations' => 'sometimes|array|max:300',
            'operations.*' => 'required|string|distinct',
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1|max:100',
            'scopes.*' => 'required|string|distinct',
            'audience' => 'required|in:api,mcp',
            'days' => 'required|integer|min:1|max:90',
            'cidrs' => 'present|array|max:20',
            'cidrs.*' => 'required|string|max:50',
        ]);
        abort_if(
            array_diff($d['scopes'], $this->catalog->scopes($r->user())),
            422,
            'Unbekannte oder unzulässige Berechtigung.',
        );
        $operations = $d['operations'] ?? null;
        if ($operations !== null) {
            $this->validateOperations($r, $operations);
        }
        if ($d['service_account_id'] ?? null) {
            $service = $this->db
                ->table('api_service_accounts')
                ->where('id', $d['service_account_id'])
                ->where('user_id', $r->user()->id)
                ->where('active', true)
                ->first();
            abort_unless($service && (string) $service->tenant_id === (string) $r->user()->tenant_id, 404);
            $allowed = json_decode($service->operations, true);
            $operations = $operations ?? $allowed;
            abort_if(
                array_diff($operations, $allowed),
                422,
                'Aktionen überschreiten die Rechte des technischen Kontos.',
            );
        }
        foreach ($d['cidrs'] as $cidr) {
            [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            abort_unless(
                filter_var($ip, FILTER_VALIDATE_IP) &&
                    ($bits === null ||
                        (ctype_digit($bits) && (int) $bits <= (str_contains($ip, ':') ? 128 : 32))),
                422,
                'Ungültiger IP-Bereich.',
            );
        }
        abort_if(
            $this->db
                ->table('api_tokens')
                ->where('user_id', $r->user()->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count() >= 50,
            422,
            'Höchstens 50 aktive Zugänge.',
        );
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $this->db->table('api_tokens')->insert([
            'id' => $id,
            'user_id' => $r->user()->id,
            'tenant_id' => $r->user()->tenant_id,
            'service_account_id' => $d['service_account_id'] ?? null,
            'operations' => $operations === null ? null : json_encode($operations),
            'name' => $d['name'],
            'scopes' => json_encode($d['scopes']),
            'audience' => $d['audience'],
            'cidrs' => json_encode($d['cidrs']),
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->addDays($d['days']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('api.token_created', $id, $r->user()->tenant_id);
        return [
            'id' => $id,
            'token' => 'ph_' . $id . '.' . $secret,
            'message' => 'Nur jetzt sichtbar. Sicher im Secret Store hinterlegen.',
        ];
    }
    public function revoke(Request $r, string $id): array
    {
        $this->authorize($r, true);
        $changed = $this->db
            ->table('api_tokens')
            ->where('id', $id)
            ->where('user_id', $r->user()->id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_unless($changed, 404);
        $this->audit->record('api.token_revoked', $id, $r->user()->tenant_id);
        return ['status' => 'revoked'];
    }
    public function approve(Request $r, string $id): array
    {
        $this->authorize($r, true);
        $changed = $this->db
            ->table('api_confirmations')
            ->where('id', $id)
            ->where('user_id', $r->user()->id)
            ->whereNull('used_at')
            ->whereNull('approved_at')
            ->where('expires_at', '>', now())
            ->update(['approved_at' => now(), 'expires_at' => now()->addMinutes(5), 'updated_at' => now()]);
        abort_unless($changed, 409, 'Bestätigung nicht mehr verfügbar.');
        $this->audit->record('api.operation_approved', $id, $r->user()->tenant_id);
        return ['status' => 'approved'];
    }
    public function settings(Request $r, string $module): array
    {
        $this->authorize($r, true);
        abort_unless($r->user()->role === 'system_admin', 403);
        abort_unless(
            in_array($module, ['api', 'mcp', ...array_column($this->catalog->all(), 'module')], true),
            404,
        );
        $d = $r->validate([
            'enabled' => 'required|boolean',
            'mcp_enabled' => 'required|boolean',
            'revision' => 'required|integer|min:0',
        ]);
        $this->db->transaction(function () use ($module, $d) {
            $this->db
                ->table('api_settings')
                ->insertOrIgnore(['module' => $module, 'created_at' => now(), 'updated_at' => now()]);
            $changed = $this->db
                ->table('api_settings')
                ->where('module', $module)
                ->where('revision', $d['revision'])
                ->update([
                    'enabled' => $d['enabled'],
                    'mcp_enabled' => $d['mcp_enabled'],
                    'revision' => $d['revision'] + 1,
                    'updated_at' => now(),
                ]);
            abort_unless($changed, 409, 'Konfiguration inzwischen geändert.');
        });
        $this->audit->record('api.module_configured', $module);
        return ['status' => 'saved'];
    }

    private function validateOperations(Request $r, array $operations): void
    {
        abort_if(
            array_diff($operations, array_column($this->catalog->manageable($r->user()), 'id')),
            422,
            'Unzulässige API-Aktion.',
        );
    }
    public function createService(Request $r): array
    {
        $this->authorize($r, true);
        $d = $r->validate([
            'name' => 'required|string|max:100',
            'operations' => 'required|array|min:1|max:300',
            'operations.*' => 'required|string|distinct',
        ]);
        $this->validateOperations($r, $d['operations']);
        abort_if(
            $this->db->table('api_service_accounts')->where('user_id', $r->user()->id)->count() >= 50,
            422,
        );
        $id = (string) Str::uuid();
        $this->db
            ->table('api_service_accounts')
            ->insert([
                'id' => $id,
                'user_id' => $r->user()->id,
                'tenant_id' => $r->user()->tenant_id,
                'name' => $d['name'],
                'operations' => json_encode($d['operations']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $this->audit->record('api.service_created', $id, $r->user()->tenant_id);
        return ['id' => $id];
    }
    public function updateService(Request $r, string $id): array
    {
        $this->authorize($r, true);
        $d = $r->validate([
            'name' => 'required|string|max:100',
            'operations' => 'present|array|max:300',
            'operations.*' => 'required|string|distinct',
            'active' => 'required|boolean',
            'revision' => 'required|integer|min:0',
        ]);
        $this->validateOperations($r, $d['operations']);
        $owned = $this->db
            ->table('api_service_accounts')
            ->where('id', $id)
            ->where('user_id', $r->user()->id)
            ->exists();
        abort_unless($owned, 404);
        $changed = $this->db
            ->table('api_service_accounts')
            ->where('id', $id)
            ->where('user_id', $r->user()->id)
            ->where('revision', $d['revision'])
            ->update([
                'name' => $d['name'],
                'active' => $d['active'],
                'operations' => json_encode($d['operations']),
                'revision' => $d['revision'] + 1,
                'updated_at' => now(),
            ]);
        abort_unless($changed, 409, 'Technisches Konto wurde inzwischen geändert.');
        $this->audit->record('api.service_updated', $id, $r->user()->tenant_id);
        return ['status' => 'saved'];
    }
    public function rotate(Request $r, string $id): array
    {
        $this->authorize($r, true);
        $d = $r->validate([
            'days' => 'required|integer|min:1|max:90',
            'overlap_hours' => 'required|integer|min:0|max:24',
        ]);
        $result = $this->db->transaction(function () use ($r, $id, $d) {
            $old = $this->db
                ->table('api_tokens')
                ->where('id', $id)
                ->where('user_id', $r->user()->id)
                ->lockForUpdate()
                ->first();
            abort_unless($old, 404);
            abort_if(
                $old->revoked_at || $old->rotated_to || $old->expires_at <= now()->toDateTimeString(),
                409,
                'Dieser Zugang kann nicht mehr rotiert werden.',
            );
            abort_unless((string) $old->tenant_id === (string) $r->user()->tenant_id, 403);
            if ($old->service_account_id) {
                abort_unless(
                    $this->db
                        ->table('api_service_accounts')
                        ->where('id', $old->service_account_id)
                        ->where('user_id', $r->user()->id)
                        ->where('active', true)
                        ->exists(),
                    409,
                );
            }
            $this->db->table('users')->where('id', $r->user()->id)->lockForUpdate()->first();
            abort_if(
                $d['overlap_hours'] > 0 &&
                    $this->db
                        ->table('api_tokens')
                        ->where('user_id', $r->user()->id)
                        ->whereNull('revoked_at')
                        ->where('expires_at', '>', now())
                        ->count() >= 50,
                422,
                'Für Übergangszeiten höchstens 50 aktive Zugänge.',
            );
            $new = (string) Str::uuid();
            $secret = bin2hex(random_bytes(32));
            $data = (array) $old;
            unset($data['id']);
            $this->db
                ->table('api_tokens')
                ->insert([
                    ...$data,
                    'id' => $new,
                    'secret_hash' => hash('sha256', $secret),
                    'expires_at' => now()->addDays($d['days']),
                    'last_used_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            $deadline = min($old->expires_at, now()->addHours($d['overlap_hours'])->toDateTimeString());
            $this->db
                ->table('api_tokens')
                ->where('id', $id)
                ->update([
                    'rotated_to' => $new,
                    'expires_at' => $deadline,
                    'revoked_at' => $d['overlap_hours'] === 0 ? now() : null,
                    'updated_at' => now(),
                ]);
            return [
                'id' => $new,
                'token' => 'ph_' . $new . '.' . $secret,
                'previous_valid_until' => $deadline,
            ];
        });
        $this->audit->record('api.token_rotated', $id, $r->user()->tenant_id);
        return $result;
    }
}
