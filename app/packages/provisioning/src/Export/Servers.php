<?php
namespace App\Modules\Provisioning\Export;
class Servers implements \App\Core\Export\ExportSource
{
    public function key(): string
    {
        return 'database-servers';
    }
    public function authorize(object $user): void
    {
        abort_unless(
            $user->active && ($user->isSystem() && $user->hasPermission('provisioning.servers.read')),
            403,
        );
    }
    public function columns(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'host' => 'Host',
            'port' => 'Port',
            'region' => 'Region',
            'purpose' => 'Zweck',
            'tls_required' => 'TLS',
            'provisioning_enabled' => 'Freigegeben',
        ];
    }
    public function query(object $user): \Illuminate\Database\Query\Builder
    {
        return app(\Illuminate\Database\DatabaseManager::class)->table('prov_db_servers');
    }
}
