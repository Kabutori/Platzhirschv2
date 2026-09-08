<?php
namespace App\Modules\Identity\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Core\Module\ModuleRegistry;
use App\Contracts\Module\AuditSink;
class RoleController
{
    public function __construct(
        private DatabaseManager $db,
        private ModuleRegistry $registry,
        private AuditSink $audit,
    ) {}
    private function authorize(Request $r): void
    {
        abort_unless($r->user()?->hasPermission('platform.roles.manage'), 403);
    }
    private function codes(): array
    {
        $codes = [];
        foreach (
            array_values(
                array_filter(
                    $this->registry->permissionFamilies(),
                    fn($family) => ($family['scope'] ?? 'administration') === 'administration',
                ),
            )
            as $f
        ) {
            foreach ($f['permissions'] as $p) {
                $codes[] = $p['code'];
            }
        }
        return $codes;
    }
    public function index(Request $r): array
    {
        $this->authorize($r);
        return [
            'families' => array_values(
                array_filter(
                    $this->registry->permissionFamilies(),
                    fn($family) => ($family['scope'] ?? 'administration') === 'administration',
                ),
            ),
            'roles' => $this->db
                ->table('identity_platform_roles')
                ->orderBy('id')
                ->get()
                ->map(function ($r) {
                    $r->permissions = json_decode($r->permissions, true);
                    $r->draft_permissions = json_decode($r->draft_permissions, true);
                    $r->locked = (bool) $r->locked;
                    return $r;
                })
                ->all(),
        ];
    }
    public function save(Request $r, ?int $id = null)
    {
        $this->authorize($r);
        $data = $r->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('identity_platform_roles')->ignore($id)],
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in($this->codes())],
            'version' => $id ? 'required|integer|min:1' : 'prohibited',
        ]);
        return $this->db->transaction(function () use ($id, $data) {
            if ($id) {
                $role = $this->db
                    ->table('identity_platform_roles')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($role, 404);
                abort_if($role->locked, 403, 'Systemrolle ist gesperrt.');
                abort_unless(
                    $role->version == $data['version'],
                    409,
                    'Rolle wurde geändert. Bitte neu laden.',
                );
                $this->db
                    ->table('identity_platform_roles')
                    ->where('id', $id)
                    ->update([
                        'name' => $data['name'],
                        'draft_permissions' => json_encode($data['permissions']),
                        'version' => $role->version + 1,
                        'tested_version' => null,
                        'updated_at' => now(),
                    ]);
            } else {
                $id = $this->db->table('identity_platform_roles')->insertGetId([
                    'name' => $data['name'],
                    'permissions' => '[]',
                    'draft_permissions' => json_encode($data['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit->record('identity.role_draft_saved', (string) $id);
            return ['id' => $id];
        });
    }
    public function delete(Request $r, int $id)
    {
        $this->authorize($r);
        $data = $r->validate(['version' => 'required|integer|min:1']);
        try {
            return $this->db->transaction(function () use ($id, $data) {
                $role = $this->db
                    ->table('identity_platform_roles')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($role, 404);
                abort_if($role->locked, 403, 'Systemrolle ist gesperrt.');
                abort_unless($role->version == $data['version'], 409);
                $this->db->table('identity_platform_roles')->where('id', $id)->delete();
                $this->audit->record('identity.role_deleted', (string) $id);
                return response()->noContent();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                abort(409, 'Rolle ist noch Benutzern zugewiesen.');
            }
            throw $e;
        }
    }
    public function check(Request $r, int $id)
    {
        return $this->transition($r, $id, false);
    }
    public function activate(Request $r, int $id)
    {
        return $this->transition($r, $id, true);
    }
    private function transition(Request $r, int $id, bool $activate): array
    {
        $this->authorize($r);
        $data = $r->validate(['version' => 'required|integer|min:1']);
        return $this->db->transaction(function () use ($r, $id, $data, $activate) {
            $role = $this->db->table('identity_platform_roles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($role, 404);
            abort_if($role->locked, 403);
            abort_unless($role->version == $data['version'], 409);
            $draft = json_decode($role->draft_permissions, true);
            abort_if(array_diff($draft, $this->codes()), 422, 'Nicht registrierte Berechtigung.');
            if ($activate) {
                abort_unless($role->tested_version == $role->version, 409, 'Entwurf zuerst prüfen.');
                abort_if(
                    $r->user()->platform_role_id == $id && !in_array('platform.roles.manage', $draft, true),
                    422,
                    'Eigene Rollenverwaltung darf nicht entzogen werden.',
                );
                $this->db
                    ->table('identity_platform_roles')
                    ->where('id', $id)
                    ->update([
                        'permissions' => $role->draft_permissions,
                        'activated_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                $this->db
                    ->table('identity_platform_roles')
                    ->where('id', $id)
                    ->update(['tested_version' => $role->version, 'updated_at' => now()]);
            }
            $this->audit->record(
                $activate ? 'identity.role_activated' : 'identity.role_validated',
                (string) $id,
            );
            return [
                'status' => $activate ? 'active' : 'validated',
                'version' => $role->version,
                'check' => 'registered_permissions',
            ];
        });
    }
}
