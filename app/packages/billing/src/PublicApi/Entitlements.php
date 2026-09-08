<?php
namespace App\Modules\Billing\PublicApi;
use Illuminate\Database\DatabaseManager;
class Entitlements implements \App\Contracts\Module\ModuleAccess
{
    public function enabled(int $tenantId): array
    {
        return $this->db
            ->table('billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('paid_until', '>', now())
            ->pluck('module_code')
            ->all();
    }
    public function __construct(private DatabaseManager $db) {}
    public function active(int $tenant, string $module): bool
    {
        return $this->db
            ->table('billing_entitlements')
            ->where('tenant_id', $tenant)
            ->where('module_code', $module)
            ->where('status', 'active')
            ->where('paid_until', '>', now())
            ->exists();
    }
    public function finish(int $tenant, string $module, string $status, string $version): void
    {
        $this->db
            ->table('billing_entitlements')
            ->where('tenant_id', $tenant)
            ->where('module_code', $module)
            ->update(['status' => $status, 'installed_version' => $version, 'updated_at' => now()]);
    }
}
