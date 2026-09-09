<?php
namespace App\Modules\Provisioning\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Config\Repository;
class ServerDirectory implements \App\Modules\Provisioning\PublicApi\ServerDirectory
{
    public function __construct(private DatabaseManager $db, private Repository $config) {}
    public function connection(int $id): array
    {
        $server = $this->db->table('prov_db_servers')->find($id);
        if (!$server || !$server->provisioning_enabled) {
            throw new \RuntimeException('server_not_authorized');
        }
        $options = [];
        if ($server->tls_required) {
            $ca = $this->config->get('platzhirsch.database_server_ca');
            if (!is_string($ca) || !is_readable($ca)) {
                throw new \RuntimeException('tls_trust_missing');
            }
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            $options[\PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
        } elseif (!in_array($server->host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('remote_tls_required');
        }
        return [
            'host' => $server->host,
            'port' => $server->port,
            'options' => $options,
            'server_version' => $server->version,
        ];
    }
    public function isEnabled(int $id): bool
    {
        return $this->db
            ->table('prov_db_servers')
            ->where('id', $id)
            ->where('provisioning_enabled', true)
            ->whereIn('purpose', ['primary', 'tenant'])
            ->exists();
    }
}
