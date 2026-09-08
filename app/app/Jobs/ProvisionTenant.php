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
        $tenant = Tenant::findOrFail($this->tenantId);
        if ($tenant->status === 'active') {
            return;
        }
        $name = $tenant->database_name;
        $user = $tenant->database_user;
        if (!preg_match('/^ph_t_[a-f0-9]{24}$/D', $name) || !preg_match('/^phu_[a-f0-9]{24}$/D', $user)) {
            throw new \RuntimeException('Invalid identifier');
        }
        // GRANT/REVOKE treat underscores as wildcards even in backtick-quoted
        // schema names. Delegate privileges for exactly this tenant database.
        $grantName = str_replace('_', '\\_', $name);
        $pdo = null;
        $granted = false;
        try {
            $database->lock($tenant->id);
            [$pdo, $host] = app(\App\Services\ProvisioningConnection::class)->open($tenant->server_id);
            $account = $pdo->quote($user) . '@' . $pdo->quote($host);
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
                "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grantName`.* TO $account",
            );
            $granted = true;
            $database->connect($tenant, true);
            $code = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => app(\App\Core\Module\ModuleRegistry::class)
                    ->get('reservation')
                    ->tenantMigrationsPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
            if ($code !== 0) {
                throw new \RuntimeException('Tenant migration failed');
            }
            if ($tenant->is_demo) {
                $connection = DB::connection('tenant');
                $connection->transaction(function () use ($connection) {
                    $room = $connection->table('rooms')->where('name', 'Testraum')->value('id');
                    if (!$room) {
                        $room = $connection->table('rooms')->insertGetId([
                            'name' => 'Testraum',
                            'color' => 'terracotta',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    foreach ([2, 4, 6] as $index => $capacity) {
                        $name = 'Testtisch ' . ($index + 1);
                        if (!$connection->table('dining_tables')->where('name', $name)->exists()) {
                            $connection->table('dining_tables')->insert([
                                'name' => $name,
                                'room_id' => $room,
                                'capacity' => $capacity,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                    for ($day = 1; $day <= 7; $day++) {
                        if (!$connection->table('opening_hours')->where('weekday', $day)->exists()) {
                            $connection->table('opening_hours')->insert([
                                'weekday' => $day,
                                'opens' => '10:00',
                                'closes' => '23:00',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
            }
            // Web credentials may edit data but may not change the schema.
            $pdo->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grantName`.* FROM $account");
            $granted = false;
            $tenant->update(['status' => 'active']);
            Audit::record('tenant.provisioned', $tenant->id, $tenant->id);
        } finally {
            try {
                if ($granted && $pdo) {
                    $pdo->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grantName`.* FROM $account");
                }
            } finally {
                $database->disconnect();
            }
            DB::purge('provision');
        }
    }
    public function failed(?\Throwable $error): void
    {
        Tenant::whereKey($this->tenantId)->update(['status' => 'failed']);
        Audit::record('tenant.provision_failed', $this->tenantId, $this->tenantId);
    }
}
