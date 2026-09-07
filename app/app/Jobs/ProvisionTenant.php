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
        // GRANT/REVOKE treat underscores as wildcards even in backtick-quoted
        // schema names. Delegate privileges for exactly this tenant database.
        $grantName = str_replace('_', '\\_', $name);
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
                "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grantName`.* TO $account",
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
