<?php
namespace App\Services;
use App\Contracts\Module\ActivationDispatcher;
use App\Models\Tenant;
use App\Jobs\ActivateTenantModule;
use Illuminate\Support\Facades\DB;
class ModuleActivationDispatcher implements ActivationDispatcher
{
    public function enable(int $tenantId, string $module): int
    {
        return DB::transaction(function () use ($tenantId, $module) {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            abort_unless($tenant->status === 'active', 409, 'Restaurant wird bereits bearbeitet.');
            $tenant->update(['status' => 'upgrading']);
            $id = DB::table('tenant_operations')->insertGetId([
                'tenant_id' => $tenantId,
                'kind' => 'module_enable',
                'status' => 'queued',
                'module_code' => $module,
                'expected_version' => $tenant->placement_version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            ActivateTenantModule::dispatch($id);
            Audit::record('module.activation_requested', $id, $tenantId);
            return $id;
        });
    }
}
