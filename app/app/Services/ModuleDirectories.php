<?php
namespace App\Services;
use App\Contracts\Module\{AccountDirectory, TenantDirectory};
use App\Models\{User, Tenant};
class ModuleDirectories implements AccountDirectory, TenantDirectory
{
    public function names(array $ids): array
    {
        return User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
    public function exists(int $id): bool
    {
        return Tenant::whereKey($id)->exists();
    }
}
