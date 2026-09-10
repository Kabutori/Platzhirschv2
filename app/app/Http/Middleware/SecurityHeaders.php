<?php
namespace App\Http\Middleware;
class SecurityHeaders
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Referrer-Policy',
            $request->is('api/v1/admin/auth/sso/callback/*', 'registrierung*') ? 'no-referrer' : 'same-origin',
        );
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        );
        if ($request->is('api/*', 'registrierung*') || $request->path() === '/') {
            $response->headers->set('Cache-Control', 'no-store');
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
