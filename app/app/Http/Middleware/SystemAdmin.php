<?php
namespace App\Http\Middleware;
class SystemAdmin
{
    public function handle($request, \Closure $next)
    {
        abort_unless($request->user()?->isSystem() && $request->user()->active, 403);
        if ($request->user()->role !== 'system_admin') {
            $path = $request->path();
            $permission = match (true) {
                str_starts_with($path, 'api/v1/admin/platform-roles') => 'platform.roles.manage',
                str_starts_with($path, 'api/v1/admin/users') => 'platform.users.read',
                $path === 'api/v1/admin/dashboard' => 'platform.dashboard.read',
                $path === 'api/v1/admin/tenants' && $request->isMethod('GET') => 'platform.tenants.read',
                $path === 'api/v1/admin/health' => 'platform.health.read',
                $path === 'api/v1/admin/audit-log' => 'platform.audit.read',
                $path === 'api/v1/admin/modules' => 'platform.modules.read',
                str_starts_with($path, 'api/v1/admin/database-servers') => $request->isMethod('GET')
                    ? 'provisioning.servers.read'
                    : (str_ends_with($path, '/test')
                        ? 'provisioning.servers.test'
                        : 'provisioning.servers.manage'),
                default => null,
            };
            abort_unless($permission && $request->user()->hasPermission($permission), 403);
        }
        return $next($request);
    }
}
