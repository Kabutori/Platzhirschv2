<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Tenant extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['database_name', 'database_user', 'database_password'];
    protected function casts(): array
    {
        return ['database_password' => 'encrypted'];
    }
}
