<?php
namespace App\Modules\IntegrationOdoo;
class StatusController
{
    public function index(\Illuminate\Http\Request $r): array
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        return [
            'module' => 'integration-odoo',
            'state' => 'placeholder',
            'configured' => false,
            'synchronization_available' => false,
            'message' =>
                'Odoo ist vorbereitet. Eine schreibende Synchronisation ist noch nicht implementiert.',
        ];
    }
}
