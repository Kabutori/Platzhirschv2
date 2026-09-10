<?php
namespace App\Jobs;
use App\Models\Tenant;
use App\Services\{TenantDatabase, ProvisioningConnection, DatabaseCopy, Audit};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
class MoveTenant implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 3600;
    public function __construct(public int $operationId)
    {
        $this->onQueue('provisioning');
    }
    public function handle(
        TenantDatabase $database,
        ProvisioningConnection $connections,
        DatabaseCopy $copy,
    ): void {
        $op = DB::table('tenant_operations')->find($this->operationId);
        if (!$op || $op->status !== 'queued') {
            return;
        }
        $database->lock($op->tenant_id);
        $source = null;
        try {
            $tenant = Tenant::findOrFail($op->tenant_id);
            if ($tenant->placement_version != $op->expected_version || $tenant->status !== 'moving') {
                throw new \RuntimeException('placement_changed');
            }
            DB::table('tenant_operations')
                ->where('id', $op->id)
                ->update(['status' => 'running', 'updated_at' => now()]);
            [$source, $sourceHost] = $connections->open($tenant->server_id);
            [$target, $targetHost] = $connections->open($op->target_server_id);
            $suffix = bin2hex(random_bytes(12));
            $name = 'ph_t_' . $suffix;
            $user = 'phu_' . $suffix;
            $password = bin2hex(random_bytes(32));
            $target->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $account = $target->quote($user) . '@' . $target->quote($targetHost);
            $grant = str_replace('_', '\\_', $name);
            $target->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $target->quote($password));
            $target->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$grant`.* TO $account");
            $manifest = $copy->copy($source, $target, $tenant->database_name, $name);
            DB::transaction(function () use ($tenant, $op, $name, $user, $password, $manifest) {
                $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if ($locked->placement_version != $op->expected_version || $locked->status !== 'moving') {
                    throw new \RuntimeException('placement_changed');
                }
                $old = [
                    'source_server_id' => $locked->server_id,
                    'source_database' => $locked->database_name,
                    'source_retained' => true,
                ];
                $locked->update([
                    'server_id' => $op->target_server_id,
                    'database_name' => $name,
                    'database_user' => $user,
                    'database_password' => $password,
                    'placement_version' => $locked->placement_version + 1,
                    'status' => 'active',
                ]);
                DB::table('tenant_operations')
                    ->where('id', $op->id)
                    ->update([
                        'status' => 'completed',
                        'result' => json_encode($old + ['tables' => $manifest]),
                        'updated_at' => now(),
                    ]);
                Audit::record('tenant.moved', $op->id, $tenant->id);
            });
        } catch (\Throwable $error) {
            DB::transaction(function () use ($op) {
                DB::table('tenant_operations')
                    ->where('id', $op->id)
                    ->where('status', '!=', 'completed')
                    ->update([
                        'status' => 'failed',
                        'error_code' => 'move_failed_source_retained',
                        'updated_at' => now(),
                    ]);
                Tenant::whereKey($op->tenant_id)
                    ->where('placement_version', $op->expected_version)
                    ->where('status', 'moving')
                    ->update(['status' => 'active']);
            });
            Audit::record('tenant.move_failed', $op->id, $op->tenant_id);
        } finally {
            if ($source) {
                try {
                    $source->exec('UNLOCK TABLES');
                } catch (\Throwable) {
                }
            }
            $database->disconnect();
        }
    }
    public function failed(?\Throwable $error): void
    {
        $op = DB::table('tenant_operations')->find($this->operationId);
        if (!$op || $op->status === 'completed') {
            return;
        }
        DB::transaction(function () use ($op) {
            DB::table('tenant_operations')
                ->where('id', $op->id)
                ->update([
                    'status' => 'failed',
                    'error_code' => 'worker_interrupted_source_retained',
                    'updated_at' => now(),
                ]);
            Tenant::whereKey($op->tenant_id)
                ->where('placement_version', $op->expected_version)
                ->where('status', 'moving')
                ->update(['status' => 'active']);
        });
    }
}
