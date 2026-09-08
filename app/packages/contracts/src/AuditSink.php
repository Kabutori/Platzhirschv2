<?php
namespace App\Contracts\Module;
interface AuditSink
{
    public function record(string $action, string|int|null $resource = null, ?int $tenantId = null): void;
}
