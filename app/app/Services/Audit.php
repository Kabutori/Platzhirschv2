<?php
namespace App\Services;
class Audit
{
    public static function record(
        string $action,
        string|int|null $resource = null,
        ?int $tenantId = null,
    ): void {
        app(\App\Contracts\Module\AuditSink::class)->record($action, $resource, $tenantId);
    }
}
