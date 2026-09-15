<?php
app('router')
    ->middleware(['web', 'auth', 'system'])
    ->get('api/v1/admin/audit-log', [\App\Modules\Audit\Http\AuditController::class, 'index']);
