<?php
namespace App\Services;
use App\Modules\Provisioning\PublicApi\ServerDirectory;
class ProvisioningConnection
{
    public function open(?int $serverId): array
    {
        if (!app()->runningInConsole()) {
            throw new \RuntimeException('worker_only');
        }
        $file = storage_path(
            'app/private/' . ($serverId ? 'server-' . $serverId . '.json' : 'provision.json'),
        );
        if (!is_readable($file)) {
            throw new \RuntimeException('provisioning_credentials_missing');
        }
        $secret = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $base = config('database.connections.mysql');
        if ($serverId) {
            $base = array_replace($base, app(ServerDirectory::class)->connection($serverId));
        }
        if ($serverId && ($secret['server_version'] ?? null) !== $base['server_version']) {
            throw new \RuntimeException('server_authorization_stale');
        }
        $account = $secret['account_host'] ?? '127.0.0.1';
        if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $account)) {
            throw new \RuntimeException('invalid_account_host');
        }
        $pdo = new \PDO(
            'mysql:host=' . $base['host'] . ';port=' . $base['port'] . ';charset=utf8mb4',
            $secret['username'],
            $secret['password'],
            ($base['options'] ?? []) + [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 15,
            ],
        );
        return [$pdo, $account];
    }
}
