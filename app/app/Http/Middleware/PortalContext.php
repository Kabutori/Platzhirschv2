<?php
namespace App\Http\Middleware;
use Illuminate\Support\Facades\Auth;
class PortalContext
{
    public function handle($request, \Closure $next)
    {
        $portal = $request->header('X-Platzhirsch-Portal');
        if (
            preg_match(
                '~^api/v1/admin/auth/sso/callback/(administration|restaurant)$~D',
                $request->path(),
                $match,
            )
        ) {
            abort_if($portal !== null && $portal !== $match[1], 400);
            $portal = $match[1];
        }
        abort_unless($portal === null || in_array($portal, ['administration', 'restaurant'], true), 400);
        // Separate session guards allow both portals to be open in the same browser.
        // The legacy API keeps its guard for existing installer integrations.
        Auth::shouldUse($portal ?? 'web');
        $request->attributes->set('portal', $portal);
        return $next($request);
    }
}
