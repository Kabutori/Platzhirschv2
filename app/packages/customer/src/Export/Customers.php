<?php
namespace App\Modules\Customer\Export;
class Customers implements \App\Core\Export\ExportSource
{
    public function key(): string
    {
        return 'customers';
    }
    public function authorize(object $user): void
    {
        abort_unless($user->active && $user->isSystem(), 403);
    }
    public function columns(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Restaurant',
            'email' => 'E-Mail',
            'phone' => 'Telefon',
            'status' => 'Status',
            'timezone' => 'Zeitzone',
            'organization_id' => 'Organisation',
        ];
    }
    public function query(object $user): \Illuminate\Database\Query\Builder
    {
        return app(\Illuminate\Database\DatabaseManager::class)->table('tenants');
    }
}
