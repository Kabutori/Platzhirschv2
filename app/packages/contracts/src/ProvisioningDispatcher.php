<?php
namespace App\Contracts\Module;
interface ProvisioningDispatcher
{
    public function create(int $tenantId): void;
}
