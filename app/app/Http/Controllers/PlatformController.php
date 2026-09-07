<?php
namespace App\Http\Controllers;
use App\Models\{Tenant, User};
use App\Jobs\ProvisionTenant;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Password, Hash};
use Illuminate\Validation\Rule;

class PlatformController
{
    public function dashboard(Request $r)
    {
        return [
            'tenants_total' => Tenant::count(),
            'tenants_active' => Tenant::where('status', 'active')->count(),
            'tenants_pending' => Tenant::where('status', 'provisioning')->count(),
            'users_total' => User::count(),
            'open_tickets' => DB::table('support_tickets')->where('status', '!=', 'closed')->count(),
            'recent_audit' => $r->user()->hasPermission('platform.audit.read')
                ? DB::table('audit_entries')->latest('id')->limit(10)->get()
                : [],
        ];
    }
    public function tenants(Request $r)
    {
        $r->validate(['search' => 'nullable|string|max:120']);
        return Tenant::when($r->input('search'), fn($q, $v) => $q->where('name', 'like', '%' . $v . '%'))
            ->latest()
            ->paginate(50);
    }
    public function createTenant(Request $r)
    {
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:2000',
            'timezone' => 'required|timezone',
        ]);
        return DB::transaction(function () use ($data) {
            $suffix = bin2hex(random_bytes(12));
            $tenant = Tenant::create([
                ...$data,
                'database_name' => 'ph_t_' . $suffix,
                'database_user' => 'phu_' . $suffix,
                'database_password' => bin2hex(random_bytes(32)),
            ]);
            ProvisionTenant::dispatch($tenant->id);
            Audit::record('tenant.created', $tenant->id, $tenant->id);
            return response()->json($tenant, 202);
        });
    }
    public function demoTenant(Request $r)
    {
        $r->merge(['email' => strtolower((string) $r->input('email'))]);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'owner_name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users,email',
            'password' => 'required|string|min:12|max:128|confirmed',
        ]);
        return DB::transaction(function () use ($data) {
            $suffix = bin2hex(random_bytes(12));
            $tenant = Tenant::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'timezone' => 'Europe/Berlin',
                'is_demo' => true,
                'database_name' => 'ph_t_' . $suffix,
                'database_user' => 'phu_' . $suffix,
                'database_password' => bin2hex(random_bytes(32)),
            ]);
            User::create([
                'name' => $data['owner_name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'restaurant_admin',
                'tenant_id' => $tenant->id,
            ]);
            ProvisionTenant::dispatch($tenant->id);
            Audit::record('tenant.demo_created', $tenant->id, $tenant->id);
            return response()->json(['tenant' => $tenant, 'login_url' => '/restaurant/login'], 202);
        });
    }
    public function updateTenant(Request $r, Tenant $tenant)
    {
        $data = $r->validate([
            'name' => 'sometimes|required|string|max:120',
            'email' => 'sometimes|required|email|max:254',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:2000',
            'status' => ['sometimes', Rule::in(['active', 'blocked'])],
        ]);
        abort_if(
            isset($data['status']) && !in_array($tenant->status, ['active', 'blocked']),
            409,
            'Provisionierung zuerst abschließen.',
        );
        $tenant->update($data);
        Audit::record('tenant.updated', $tenant->id, $tenant->id);
        return $tenant;
    }
    public function retryTenant(Tenant $tenant)
    {
        abort_unless($tenant->status === 'failed', 409);
        $tenant->update(['status' => 'provisioning']);
        ProvisionTenant::dispatch($tenant->id);
        Audit::record('tenant.retry', $tenant->id, $tenant->id);
        return response()->json($tenant, 202);
    }
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
            'tenant_id' => 'nullable|exists:tenants,id',
            'platform_role_id' => 'nullable|integer',
            'password' => 'required|string|min:12|max:128',
        ]);
        abort_if(
            in_array($data['role'], ['system_admin', 'platform_staff'], true) === !empty($data['tenant_id']),
            422,
            'Rolle und Restaurant passen nicht zusammen.',
        );
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
        Audit::record('user.created', $user->id, $user->tenant_id);
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
        return DB::transaction(function () use ($r, $user, $data) {
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
                Audit::record('user.platform_role_changed', $user->id);
            }
            unset($data['expected_platform_role_id']);
            $user->update($data);
            if (!$user->active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record('user.updated', $user->id, $user->tenant_id);
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
        $status = Password::sendResetLink(['email' => $user->email]);
        abort_unless(
            $status === Password::RESET_LINK_SENT,
            422,
            'Einladung nicht versendet; bitte später erneut versuchen.',
        );
        Audit::record('user.invited', $user->id, $user->tenant_id);
        return ['message' => 'Einrichtungslink versendet.'];
    }
    public function audit()
    {
        return DB::table('audit_entries')->latest('id')->paginate(100);
    }
    public function health()
    {
        $start = microtime(true);
        DB::select('SELECT 1');
        return [
            'version' => config('platzhirsch.version'),
            'php' => PHP_VERSION,
            'database' => 'MySQL',
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            'queued_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'mail_configured' => config('mail.default') !== 'log',
            'scheduler_last_seen' => cache()->get('scheduler_last_seen'),
        ];
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
                'permissions' => ['restaurant.manage', 'reservation.manage'],
            ],
            [
                'code' => 'staff',
                'name' => 'Mitarbeiter',
                'locked' => true,
                'permissions' => ['reservation.manage'],
            ],
        ];
    }
}
