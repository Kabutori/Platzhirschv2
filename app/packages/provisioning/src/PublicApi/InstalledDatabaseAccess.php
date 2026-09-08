<?php
namespace App\Modules\Provisioning\PublicApi;
interface InstalledDatabaseAccess
{
    public function connections(): array;
    /** @return array{password:string,tenant_id:?int} */
    public function credential(string $connection): array;
}
