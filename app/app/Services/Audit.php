<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class Audit
{
    public static function record(
        string $action,
        string|int|null $resource = null,
        ?int $tenantId = null,
    ): void {
        DB::table('audit_entries')->insert([
            'actor_id' => auth()->id(),
            'tenant_id' => $tenantId,
            'action' => $action,
            'resource' => $resource === null ? null : (string) $resource,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
