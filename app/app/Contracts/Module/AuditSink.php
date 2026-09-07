<?php
namespace App\Contracts\Module;
interface AuditSink
{
    public function record(string $action, string $resource): void;
}
