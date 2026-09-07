<?php
namespace App\Http\Middleware;
class SystemAdmin
{
    public function handle($request, \Closure $next)
    {
        abort_unless($request->user()?->isSystem() && $request->user()->active, 403);
        return $next($request);
    }
}
