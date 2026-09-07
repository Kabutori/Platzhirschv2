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
        return $this->role === 'system_admin' && $this->tenant_id === null;
    }
    public function canManage(): bool
    {
        return $this->isSystem() || $this->role === 'restaurant_admin';
    }
}
