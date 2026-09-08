<?php
namespace App\Modules\Identity\Http;
use App\Modules\Identity\Domain\User;
use App\Contracts\Module\AuditSink;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
class TeamController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
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
        return $this->db->transaction(function () use ($r, $data) {
            $this->validateRole($data, $r->attributes->get('tenant')->id);
            $user = User::create([
                ...$data,
                'email' => strtolower($data['email']),
                'tenant_id' => $r->attributes->get('tenant')->id,
            ]);
            $this->audit->record('team.created', $user->id, $user->tenant_id);
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
            $this->db
                ->table('restaurant_roles')
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
        return $this->db->transaction(function () use ($r, $tenant, $id, $data) {
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
            $this->db->table('sessions')->where('user_id', $id)->delete();
            $this->audit->record('team.updated', $id, $tenant);
            return $user;
        });
    }
}
