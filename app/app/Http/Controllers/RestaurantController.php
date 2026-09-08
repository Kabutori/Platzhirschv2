<?php
namespace App\Http\Controllers;
use App\Services\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RestaurantController
{
    public function profile(Request $r)
    {
        return $r->attributes->get('tenant');
    }
    public function updateProfile(Request $r)
    {
        abort_unless($r->user()->hasPermission('restaurant.profile'), 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:2000',
        ]);
        $tenant = $r->attributes->get('tenant');
        $tenant->update($data);
        Audit::record('restaurant.profile_updated', $tenant->id, $tenant->id);
        return $tenant;
    }
    public function team(Request $r)
    {
        abort_unless($r->user()->hasPermission('team.manage'), 403);
        return User::where('tenant_id', $r->attributes->get('tenant')->id)
            ->orderBy('name')
            ->get();
    }
    public function createTeam(Request $r)
    {
        abort_unless($r->user()->hasPermission('team.manage'), 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users,email',
            'password' => 'required|string|min:12|max:128',
            'role' => ['required', Rule::in(['restaurant_admin', 'staff'])],
            'restaurant_role_id' => 'nullable|integer',
        ]);
        return DB::transaction(function () use ($r, $data) {
            $this->validateRole($data, $r->attributes->get('tenant')->id);
            $user = User::create([
                ...$data,
                'email' => strtolower($data['email']),
                'tenant_id' => $r->attributes->get('tenant')->id,
            ]);
            Audit::record('team.created', $user->id, $user->tenant_id);
            return response()->json($user, 201);
        });
    }
    private function validateRole(array $data, int $tenant): void
    {
        if (empty($data['restaurant_role_id'])) {
            return;
        }
        abort_unless(
            ($data['role'] ?? 'staff') === 'staff',
            422,
            'Eigene Rollen gelten nur für Mitarbeiter.',
        );
        abort_unless(
            DB::table('restaurant_roles')
                ->where('tenant_id', $tenant)
                ->where('id', $data['restaurant_role_id'])
                ->lockForUpdate()
                ->exists(),
            422,
            'Rolle gehört nicht zu diesem Restaurant.',
        );
    }
    public function updateTeam(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('team.manage'), 403);
        $tenant = $r->attributes->get('tenant')->id;
        $data = $r->validate([
            'name' => 'sometimes|required|string|max:120',
            'active' => 'sometimes|boolean',
            'role' => ['required', Rule::in(['restaurant_admin', 'staff'])],
            'restaurant_role_id' => 'nullable|integer',
        ]);
        return DB::transaction(function () use ($r, $tenant, $id, $data) {
            $this->validateRole($data, $tenant);
            $user = User::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id);
            abort_if(
                $user->id === $r->user()->id &&
                    (($data['active'] ?? true) === false ||
                        $data['role'] !== $user->role ||
                        !empty($data['restaurant_role_id'])),
                422,
                'Eigene Administratorrechte können hier nicht entzogen werden.',
            );
            $user->update([...$data, 'restaurant_role_id' => $data['restaurant_role_id'] ?? null]);
            DB::table('sessions')->where('user_id', $id)->delete();
            Audit::record('team.updated', $id, $tenant);
            return $user;
        });
    }
}
