<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable
{
    use Notifiable;
    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_last_step'];
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }
    public function isSystem(): bool
    {
        return in_array($this->role, ['system_admin', 'platform_staff'], true) && $this->tenant_id === null;
    }
    public function permissions(): array
    {
        if (!$this->active) {
            return [];
        }
        if ($this->isSystem()) {
            return $this->role === 'system_admin'
                ? ['*']
                : app(\App\Modules\Identity\PublicApi\RoleDirectory::class)->permissions(
                    $this->platform_role_id,
                );
        }
        if ($this->role === 'restaurant_admin') {
            return [...array_keys(\App\Services\Permissions::CATALOG), 'team.manage', 'roles.manage'];
        }
        if ($this->role !== 'staff') {
            return [];
        }
        if (!$this->restaurant_role_id) {
            return \App\Services\Permissions::STAFF;
        }
        // Resolve on every request; a stale or foreign role must never grant rights.
        $json = \Illuminate\Support\Facades\DB::table('restaurant_roles')
            ->where('id', $this->restaurant_role_id)
            ->where('tenant_id', $this->tenant_id)
            ->value('permissions');
        return array_values(
            array_intersect(json_decode($json ?? '[]', true), array_keys(\App\Services\Permissions::CATALOG)),
        );
    }
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
    public function canManage(): bool
    {
        return $this->active && ($this->role === 'system_admin' || $this->role === 'restaurant_admin');
    }
}
