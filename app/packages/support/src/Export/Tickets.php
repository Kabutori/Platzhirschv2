<?php
namespace App\Modules\Support\Export;
class Tickets implements \App\Core\Export\ExportSource
{
    public function key(): string
    {
        return 'support-tickets';
    }
    public function authorize(object $user): void
    {
        abort_unless($user->active && $user->hasPermission('support.access'), 403);
    }
    public function columns(): array
    {
        return [
            'id' => 'ID',
            'tenant_id' => 'Restaurant',
            'subject' => 'Betreff',
            'priority' => 'Priorität',
            'status' => 'Status',
            'created_at' => 'Erstellt',
            'updated_at' => 'Geändert',
        ];
    }
    public function query(object $user): \Illuminate\Database\Query\Builder
    {
        return app(\Illuminate\Database\DatabaseManager::class)
            ->table('support_tickets')
            ->when(!$user->isSystem(), fn($q) => $q->where('tenant_id', $user->tenant_id));
    }
}
