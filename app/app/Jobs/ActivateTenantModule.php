<?php
namespace App\Jobs;
use App\Models\Tenant;
use App\Services\{TenantDatabase, ProvisioningConnection, Audit};
use App\Core\Module\ModuleRegistry;
use App\Modules\Billing\PublicApi\Entitlements;
use Illuminate\Support\Facades\{DB, Artisan};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class ActivateTenantModule implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 600;
    public function __construct(public int $operationId)
    {
        $this->onQueue('provisioning');
    }
    public function handle(
        TenantDatabase $database,
        ProvisioningConnection $connections,
        ModuleRegistry $registry,
        Entitlements $entitlements,
    ): void {
        $op = DB::table('tenant_operations')->find($this->operationId);
        if (!$op || $op->status !== 'queued') {
            return;
        }
        $pdo = null;
        $granted = false;
        $account = $grant = '';
        $version = '';
        $success = false;
        try {
            $database->lock($op->tenant_id);
            $tenant = Tenant::findOrFail($op->tenant_id);
            if ($tenant->status !== 'upgrading' || $tenant->placement_version != $op->expected_version) {
                throw new \RuntimeException('tenant_state_changed');
            }
            $module = $registry->get($op->module_code);
            $version = $module->version();
            DB::table('tenant_operations')
                ->where('id', $op->id)
                ->update(['status' => 'running', 'updated_at' => now()]);
            [$pdo, $host] = $connections->open($tenant->server_id);
            $account = $pdo->quote($tenant->database_user) . '@' . $pdo->quote($host);
            $grant = str_replace('_', '\\_', $tenant->database_name);
            $pdo->exec("GRANT CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grant`.* TO $account");
            $granted = true;
            $database->connect($tenant, true);
            $path = $module->tenantMigrationsPath();
            if (
                $path &&
                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => $path,
                    '--realpath' => true,
                    '--force' => true,
                ]) !== 0
            ) {
                throw new \RuntimeException('migration_failed');
            }
            $pdo->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grant`.* FROM $account");
            $granted = false;
            DB::transaction(function () use ($tenant, $op, $version, $entitlements) {
                $entitlements->finish($tenant->id, $op->module_code, 'active', $version);
                Tenant::whereKey($tenant->id)
                    ->where('status', 'upgrading')
                    ->update(['status' => 'active']);
                DB::table('tenant_operations')
                    ->where('id', $op->id)
                    ->update(['status' => 'completed', 'updated_at' => now()]);
                Audit::record('module.activated', $op->module_code, $tenant->id);
            });
            $success = true;
        } catch (\Throwable) {
            $entitlements->finish($op->tenant_id, $op->module_code, 'error', $version);
            DB::table('tenant_operations')
                ->where('id', $op->id)
                ->update(['status' => 'failed', 'error_code' => 'activation_failed', 'updated_at' => now()]);
        } finally {
            if ($granted && $pdo) {
                try {
                    $pdo->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grant`.* FROM $account");
                    $granted = false;
                } catch (\Throwable) {
                }
            }
            if (!$success && !$granted) {
                Tenant::whereKey($op->tenant_id)
                    ->where('status', 'upgrading')
                    ->update(['status' => 'active']);
            }
            $database->disconnect();
        }
    }
    public function failed(?\Throwable $e): void
    {
        DB::table('tenant_operations')
            ->where('id', $this->operationId)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                'error_code' => 'worker_interrupted_manual_repair',
                'updated_at' => now(),
            ]);
    }
}
