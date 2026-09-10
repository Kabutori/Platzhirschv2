<?php
namespace App\Http\Middleware;
use App\Models\Tenant;
use App\Services\TenantDatabase;
class TenantContext
{
    public function handle($request, \Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->active, 403);
        abort_if(
            $user->isSystem() && ($request->attributes->get('portal') || $user->role !== 'system_admin'),
            403,
            'Bitte im Restaurantportal anmelden.',
        );
        // Only a platform administrator may select a tenant. Restaurant users cannot override it.
        $id = $user->isSystem() ? $request->header('X-Tenant-ID') : $user->tenant_id;
        abort_unless($id && ctype_digit((string) $id), 422, 'Bitte ein Restaurant auswählen.');
        $tenant = Tenant::findOrFail($id);
        abort_unless($tenant->status === 'active', 403, 'Restaurant ist nicht freigeschaltet.');
        $request->attributes->set('tenant', $tenant);
        $db = app(TenantDatabase::class);
        $db->connect($tenant);
        try {
            return $next($request);
        } finally {
            $db->disconnect();
        }
    }
}
