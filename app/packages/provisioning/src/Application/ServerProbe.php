<?php
namespace App\Modules\Provisioning\Application;
use PDO;
use PDOException;
class ServerProbe
{
    public function __construct(private \Illuminate\Contracts\Config\Repository $config) {}
    public function run(array $server, bool $permissions): array
    {
        $started = microtime(true);
        try {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
            // Use a TLS handshake and require a negotiated cipher. Certificate trust is
            // provided by the machine; no user-controlled certificate file paths.
            if ($server['tls_required']) {
                $ca = $this->config->get('platzhirsch.database_server_ca');
                if (!is_string($ca) || !is_readable($ca)) {
                    return ['ok' => false, 'code' => 'tls_trust_missing'];
                }
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
            }
            $pdo = new PDO(
                'mysql:host=' .
                    $server['host'] .
                    ';port=' .
                    $server['port'] .
                    ';dbname=' .
                    $server['database'] .
                    ';charset=utf8mb4',
                $server['username'],
                $server['password'],
                $options,
            );
            if ($server['tls_required']) {
                $cipher = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
                if (!$cipher || !$cipher[1]) {
                    return ['ok' => false, 'code' => 'tls_required'];
                }
            }
            $pdo->query('SELECT 1')->fetchColumn();
            if ($permissions) {
                // The check has no writes to existing schemas. It establishes whether this
                // account can use a temporary table, not whether tenant provisioning works.
                $table = 'ph_probe_' . bin2hex(random_bytes(8));
                try {
                    $pdo->exec("CREATE TEMPORARY TABLE `$table` (id INT PRIMARY KEY, value INT)");
                    $pdo->exec("INSERT INTO `$table` VALUES (1, 1)");
                    $pdo->exec("UPDATE `$table` SET value = 2 WHERE id = 1");
                    if ((int) $pdo->query("SELECT value FROM `$table` WHERE id = 1")->fetchColumn() !== 2) {
                        return ['ok' => false, 'code' => 'permission_check_failed'];
                    }
                    $pdo->exec("DELETE FROM `$table` WHERE id = 1");
                } finally {
                    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `$table`");
                }
            }
            return [
                'ok' => true,
                'code' => $permissions ? 'temporary_table_checked' : 'connected',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (PDOException $e) {
            // Never expose a driver exception, SQL, host details or credentials in HTTP/logs.
            $code = (int) ($e->errorInfo[1] ?? 0);
            return [
                'ok' => false,
                'code' => match ($code) {
                    1045 => 'authentication_failed',
                    1044, 1142, 1227 => 'permission_denied',
                    1049 => 'database_missing',
                    default => 'connection_failed',
                },
            ];
        }
    }
}
