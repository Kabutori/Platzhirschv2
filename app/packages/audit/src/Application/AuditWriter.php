<?php
namespace App\Modules\Audit\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
class AuditWriter implements \App\Contracts\Module\AuditSink
{
    public function __construct(private DatabaseManager $db, private Request $request) {}
    public function record(string $action, string|int|null $resource = null, ?int $tenantId = null): void
    {
        $this->db
            ->table('audit_entries')
            ->insert([
                'actor_id' => $this->request->user()?->id,
                'tenant_id' => $tenantId,
                'action' => $action,
                'resource' => $resource === null ? null : (string) $resource,
                'ip' => app()->runningInConsole() ? null : $this->request->ip(),
                'created_at' => now(),
            ]);
    }
}
