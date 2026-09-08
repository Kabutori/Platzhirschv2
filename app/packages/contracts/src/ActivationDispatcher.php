<?php
namespace App\Contracts\Module;
interface ActivationDispatcher
{
    public function enable(int $tenantId, string $module): int;
}
