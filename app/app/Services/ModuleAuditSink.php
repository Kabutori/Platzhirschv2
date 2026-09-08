<?php
namespace App\Services;
use App\Contracts\Module\AuditSink;
final class ModuleAuditSink implements AuditSink
{
    public function record(string $action, string|int|null $resource = null, ?int $tenantId = null): void
    {
        Audit::record($action, $resource, $tenantId);
    }
}
