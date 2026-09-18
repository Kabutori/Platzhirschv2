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
        $this->db
            ->table('api_tokens')
            ->insert([
                'id' => $id,
                'user_id' => $r->user()->id,
                'tenant_id' => $r->user()->tenant_id,
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
}
