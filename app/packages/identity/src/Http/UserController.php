<?php
namespace App\Modules\Identity\Http;
use App\Modules\Identity\Domain\User;
use App\Contracts\Module\{AuditSink, TenantDirectory};
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class UserController
{
    public function __construct(
        private DatabaseManager $db,
        private AuditSink $audit,
        private PasswordBroker $passwords,
        private TenantDirectory $tenants,
    ) {}
    public function users()
    {
        return User::orderBy('name')->get();
    }
    public function createUser(Request $r)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users,email',
            'role' => ['required', Rule::in(['system_admin', 'platform_staff', 'restaurant_admin', 'staff'])],
            'tenant_id' => 'nullable|integer',
            'platform_role_id' => 'nullable|integer',
            'password' => 'required|string|min:12|max:128',
        ]);
        abort_if(
            in_array($data['role'], ['system_admin', 'platform_staff'], true) === !empty($data['tenant_id']),
            422,
            'Rolle und Restaurant passen nicht zusammen.',
        );
        if (!empty($data['tenant_id'])) {
            abort_unless(
                $this->tenants->exists((int) $data['tenant_id']),
                422,
                'Restaurant existiert nicht.',
            );
        }
        if ($data['role'] === 'platform_staff') {
            abort_unless(
                !empty($data['platform_role_id']) &&
                    app(\App\Modules\Identity\PublicApi\RoleDirectory::class)->assignable(
                        $data['platform_role_id'],
                    ),
                422,
                'Aktive Plattformrolle auswählen.',
            );
        } else {
            unset($data['platform_role_id']);
        }
        $user = User::create([...$data, 'email' => strtolower($data['email'])]);
        $this->audit->record('user.created', $user->id, $user->tenant_id);
        return response()->json($user, 201);
    }
    public function updateUser(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        $data = $r->validate([
            'name' => 'sometimes|required|string|max:120',
            'active' => 'sometimes|boolean',
            'platform_role_id' =>
                $user->role === 'platform_staff' ? 'sometimes|required|integer' : 'prohibited',
            'expected_platform_role_id' => 'required_with:platform_role_id|integer',
        ]);
        return $this->db->transaction(function () use ($r, $user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $user->id === $r->user()->id && isset($data['active']) && !$data['active'],
                422,
                'Eigenes Konto kann nicht gesperrt werden.',
            );
            if (isset($data['platform_role_id'])) {
                abort_unless(
                    $user->role === 'platform_staff' &&
                        $user->platform_role_id == $data['expected_platform_role_id'],
                    409,
                    'Rollenzuordnung wurde geändert. Bitte neu laden.',
                );
                abort_unless(
                    app(\App\Modules\Identity\PublicApi\RoleDirectory::class)->assignable(
                        $data['platform_role_id'],
                    ),
                    422,
                    'Aktive Plattformrolle auswählen.',
                );
                $this->audit->record('user.platform_role_changed', $user->id);
            }
            unset($data['expected_platform_role_id']);
            $user->update($data);
            if (!$user->active) {
                $this->db->table('sessions')->where('user_id', $user->id)->delete();
            }
            $this->audit->record('user.updated', $user->id, $user->tenant_id);
            return $user;
        });
    }
    public function invite(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        abort_if(
            config('mail.default') === 'log',
            422,
            'SMTP muss zunächst in der Serverkonfiguration eingerichtet werden.',
        );
        $status = $this->passwords->sendResetLink(['email' => $user->email]);
        abort_unless(
            $status === PasswordBroker::RESET_LINK_SENT,
            422,
            'Einladung nicht versendet; bitte später erneut versuchen.',
        );
        $this->audit->record('user.invited', $user->id, $user->tenant_id);
        return ['message' => 'Einrichtungslink versendet.'];
    }
    public function roles()
    {
        return [
            [
                'code' => 'system_admin',
                'name' => 'System-Administrator',
                'locked' => true,
                'permissions' => ['*'],
            ],
            [
                'code' => 'restaurant_admin',
                'name' => 'Restaurant-Administrator',
                'locked' => true,
                'permissions' => [
                    ...array_keys(\App\Modules\Identity\Application\Permissions::catalog()),
                    'team.manage',
                    'roles.manage',
                    'modules.manage',
                ],
            ],
            [
                'code' => 'staff',
                'name' => 'Mitarbeiter',
                'locked' => true,
                'permissions' => \App\Modules\Identity\Application\Permissions::STAFF,
            ],
        ];
    }
}
