<?php
namespace App\Services;
use App\Contracts\Module\AuditSink;
final class ModuleAuditSink implements AuditSink
{
    public function record(string $action, string $resource): void
    {
        Audit::record($action, $resource);
    }
}
