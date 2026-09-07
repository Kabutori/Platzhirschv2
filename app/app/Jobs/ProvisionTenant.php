<?php
namespace App\Jobs;
use App\Models\Tenant;
use App\Services\{TenantDatabase, Audit};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Artisan};
class ProvisionTenant implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 120;
    public function __construct(public int $tenantId)
    {
        $this->onQueue('provisioning');
    }
    public function handle(TenantDatabase $database): void
    {
        // This credential file is readable by the provisioning service, NOT the IIS identity.
        $file = storage_path('app/private/provision.json');
        if (!is_readable($file)) {
            throw new \RuntimeException('Provisioning-Zugang nicht eingerichtet.');
        }
        $credentials = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        config([
            'database.connections.provision.username' => $credentials['username'],
            'database.connections.provision.password' => $credentials['password'],
        ]);
        DB::purge('provision');
        $tenant = Tenant::findOrFail($this->tenantId);
        if ($tenant->status === 'active') {
            return;
        }
        $name = $tenant->database_name;
        $user = $tenant->database_user;
        if (!preg_match('/^ph_t_[a-f0-9]{24}$/D', $name) || !preg_match('/^phu_[a-f0-9]{24}$/D', $user)) {
            throw new \RuntimeException('Invalid identifier');
        }
        try {
            $pdo = DB::connection('provision')->getPdo();
            $account = $pdo->quote($user) . "@'127.0.0.1'";
            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            );
            $pdo->exec(
                'CREATE USER IF NOT EXISTS ' .
                    $account .
                    ' IDENTIFIED BY ' .
                    $pdo->quote($tenant->database_password),
            );
            $pdo->exec(
                "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON `$name`.* TO $account",
            );
            $database->connect($tenant);
            $code = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/tenant',
                '--force' => true,
            ]);
            if ($code !== 0) {
                throw new \RuntimeException('Tenant migration failed');
            }
            // Web credentials may edit data but may not change the schema.
            $pdo->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$name`.* FROM $account");
            $tenant->update(['status' => 'active']);
            Audit::record('tenant.provisioned', $tenant->id, $tenant->id);
        } finally {
            $database->disconnect();
            DB::purge('provision');
        }
    }
    public function failed(?\Throwable $error): void
    {
        Tenant::whereKey($this->tenantId)->update(['status' => 'failed']);
        Audit::record('tenant.provision_failed', $this->tenantId, $this->tenantId);
    }
}
