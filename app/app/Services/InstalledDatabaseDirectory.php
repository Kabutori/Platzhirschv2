<?php
namespace App\Services;
use App\Models\Tenant;
use App\Modules\Provisioning\PublicApi\InstalledDatabaseAccess;
class InstalledDatabaseDirectory implements InstalledDatabaseAccess
{
    public function connections(): array
    {
        $config = config('database.connections.mysql');
        $rows = [
            [
                'id' => 'platform',
                'name' => 'Plattform-Datenbank',
                'scope' => 'Plattform',
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $config['database'],
                'username' => $config['username'],
                'status' => 'active',
            ],
        ];
        foreach (
            Tenant::whereIn('status', ['active', 'blocked'])
                ->orderBy('name')
                ->get()
            as $tenant
        ) {
            $rows[] = [
                'id' => (string) $tenant->id,
                'name' => $tenant->name,
                'scope' => 'Restaurant',
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $tenant->database_name,
                'username' => $tenant->database_user,
                'status' => $tenant->status,
            ];
        }
        return $rows;
    }
    public function credential(string $connection): array
    {
        if ($connection === 'platform') {
            return [
                'password' => (string) config('database.connections.mysql.password'),
                'tenant_id' => null,
            ];
        }
        abort_unless(ctype_digit($connection), 404);
        $tenant = Tenant::whereIn('status', ['active', 'blocked'])->findOrFail($connection);
        return ['password' => (string) $tenant->database_password, 'tenant_id' => $tenant->id];
    }
}
