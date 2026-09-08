<?php
namespace App\Modules\Customer\Http;
use App\Modules\Customer\Domain\Tenant;
use App\Contracts\Module\{AuditSink, ProvisioningDispatcher, AccountProvisioner};
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class TenantController
{
    public function __construct(
        private DatabaseManager $db,
        private AuditSink $audit,
        private ProvisioningDispatcher $provisioning,
        private AccountProvisioner $accounts,
    ) {}
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
            'server_id' => 'nullable|integer',
        ]);
        if (!empty($data['server_id'])) {
            abort_unless(
                app(\App\Contracts\Module\ServerTargets::class)->isEnabled($data['server_id']),
                422,
                'Zielserver ist nicht für Provisionierung freigegeben.',
            );
        }
        return $this->db->transaction(function () use ($data) {
            $suffix = bin2hex(random_bytes(12));
            $tenant = Tenant::create([
                ...$data,
                'database_name' => 'ph_t_' . $suffix,
                'database_user' => 'phu_' . $suffix,
                'database_password' => bin2hex(random_bytes(32)),
            ]);
            $this->provisioning->create($tenant->id);
            $this->audit->record('tenant.created', $tenant->id, $tenant->id);
            return response()->json($tenant, 202);
        });
    }
    public function demoTenant(Request $r)
    {
        $r->merge(['email' => strtolower((string) $r->input('email'))]);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'owner_name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'password' => 'required|string|min:12|max:128|confirmed',
        ]);
        return $this->db->transaction(function () use ($data) {
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
            $this->accounts->createOwner([
                'name' => $data['owner_name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'restaurant_admin',
                'tenant_id' => $tenant->id,
            ]);
            $this->provisioning->create($tenant->id);
            $this->audit->record('tenant.demo_created', $tenant->id, $tenant->id);
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
        $this->audit->record('tenant.updated', $tenant->id, $tenant->id);
        return $tenant;
    }
    public function retryTenant(Tenant $tenant)
    {
        abort_unless($tenant->status === 'failed', 409);
        $tenant->update(['status' => 'provisioning']);
        $this->provisioning->create($tenant->id);
        $this->audit->record('tenant.retry', $tenant->id, $tenant->id);
        return response()->json($tenant, 202);
    }
}
