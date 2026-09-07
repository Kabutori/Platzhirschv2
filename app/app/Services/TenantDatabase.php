<?php
namespace App\Services;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
class TenantDatabase
{
    public function connect(Tenant $tenant): void
    {
        if (!preg_match('/^ph_t_[a-f0-9]{24}$/D', $tenant->database_name)) {
            throw new \RuntimeException('Invalid tenant database');
        }
        config([
            'database.connections.tenant' => array_replace(config('database.connections.mysql'), [
                'database' => $tenant->database_name,
                'username' => $tenant->database_user,
                'password' => $tenant->database_password,
            ]),
        ]);
        DB::purge('tenant');
    }
    public function disconnect(): void
    {
        DB::purge('tenant');
        config(['database.connections.tenant' => config('database.connections.mysql')]);
    }
}
