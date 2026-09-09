<?php
namespace App\Services;
use App\Contracts\Module\TenantRuntime;
use App\Models\Tenant;
class ModuleTenantRuntime implements TenantRuntime
{
    public function withTenant(int $id, \Closure $callback): mixed
    {
        $tenant = Tenant::findOrFail($id);
        abort_unless($tenant->status === 'active', 403);
        $database = app(TenantDatabase::class);
        $database->connect($tenant);
        try {
            return $callback(
                (object) ['id' => $tenant->id, 'name' => $tenant->name, 'timezone' => $tenant->timezone],
            );
        } finally {
            $database->disconnect();
        }
    }
}
