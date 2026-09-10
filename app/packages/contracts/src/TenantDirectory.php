<?php
namespace App\Contracts\Module;
interface TenantDirectory
{
    public function exists(int $id): bool;
}
