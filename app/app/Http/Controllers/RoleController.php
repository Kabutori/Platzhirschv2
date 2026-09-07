<?php
namespace App\Http\Controllers;
use App\Services\{Permissions, Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class RoleController
{
    private function tenant(Request $r): int
    {
        abort_unless($r->user()->hasPermission('roles.manage'), 403);
        return $r->attributes->get('tenant')->id;
    }
    public function index(Request $r)
    {
        $tenant = $this->tenant($r);
        return [
            'catalog' => Permissions::CATALOG,
            'roles' => DB::table('restaurant_roles')
                ->where('tenant_id', $tenant)
                ->orderBy('name')
                ->get()
                ->map(function ($role) {
                    $role->permissions = json_decode($role->permissions, true);
                    return $role;
                }),
        ];
    }
    public function save(Request $r, ?int $id = null)
    {
        $tenant = $this->tenant($r);
        $data = $r->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('restaurant_roles')->where('tenant_id', $tenant)->ignore($id),
            ],
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(Permissions::CATALOG))],
            'version' => $id ? 'required|integer|min:1' : 'sometimes|integer',
        ]);
        abort_if(
            count(
                array_intersect($data['permissions'], [
                    'reservation.write',
                    'reservation.cancel',
                    'reservation.export',
                ]),
            ) > 0 && !in_array('reservation.read', $data['permissions'], true),
            422,
            'Für Buchungsänderungen und Export muss auch Lesezugriff erlaubt sein.',
        );
        return DB::transaction(function () use ($id, $tenant, $data) {
            $query = DB::table('restaurant_roles')->where('tenant_id', $tenant)->where('id', $id);
            if ($id) {
                $role = $query->lockForUpdate()->first();
                abort_unless($role, 404);
                abort_unless(
                    $role->version === $data['version'],
                    409,
                    'Rolle wurde inzwischen geändert. Bitte neu laden.',
                );
                $query->update([
                    'name' => $data['name'],
                    'permissions' => json_encode($data['permissions']),
                    'version' => $role->version + 1,
                    'updated_at' => now(),
                ]);
            } else {
                $id = DB::table('restaurant_roles')->insertGetId([
                    'tenant_id' => $tenant,
                    'name' => $data['name'],
                    'permissions' => json_encode($data['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Audit::record('role.saved', $id, $tenant);
            return response()->json(['id' => $id], 200);
        });
    }
    public function delete(Request $r, int $id)
    {
        $tenant = $this->tenant($r);
        return DB::transaction(function () use ($tenant, $id) {
            $query = DB::table('restaurant_roles')->where('tenant_id', $tenant)->where('id', $id);
            abort_unless($query->lockForUpdate()->first(), 404);
            abort_if(
                DB::table('users')->where('restaurant_role_id', $id)->exists(),
                409,
                'Rolle ist noch Benutzern zugewiesen.',
            );
            $query->delete();
            Audit::record('role.deleted', $id, $tenant);
            return response()->noContent();
        });
    }
}
