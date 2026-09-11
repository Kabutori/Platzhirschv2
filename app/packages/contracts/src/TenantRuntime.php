<?php
namespace App\Contracts\Module;
interface TenantRuntime
{
    public function withTenant(int $id, \Closure $callback): mixed;
}
