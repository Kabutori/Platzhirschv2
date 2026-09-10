<?php
namespace App\Contracts\Module;
interface ModuleAccess
{
    public function enabled(int $tenantId): array;
}
