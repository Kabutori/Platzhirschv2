<?php
namespace App\Modules\Identity\PublicApi;
interface RoleDirectory
{
    public function permissions(?int $roleId): array;
    public function assignable(int $roleId): bool;
}
