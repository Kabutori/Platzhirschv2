<?php
namespace App\Http\Middleware;
use Illuminate\Support\Facades\Auth;
class PortalContext
{
    public function handle($request, \Closure $next)
    {
        $portal = $request->header('X-Platzhirsch-Portal');
        abort_unless($portal === null || in_array($portal, ['administration', 'restaurant'], true), 400);
        // Separate session guards allow both portals to be open in the same browser.
        // The legacy API keeps its guard for existing installer integrations.
        Auth::shouldUse($portal ?? 'web');
        $request->attributes->set('portal', $portal);
        return $next($request);
    }
}
