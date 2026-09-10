<?php
namespace App\Services;
class ProvisioningDispatcher implements \App\Contracts\Module\ProvisioningDispatcher
{
    public function create(int $tenantId): void
    {
        \App\Jobs\ProvisionTenant::dispatch($tenantId);
    }
}
