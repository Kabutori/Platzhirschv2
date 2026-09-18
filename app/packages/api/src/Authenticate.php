<?php
namespace App\Modules\Api;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
class Authenticate
{
    public function __construct(
        private DatabaseManager $db,
        private Factory $auth,
        private RateLimiter $limits,
    ) {}
    public function handle($r, \Closure $next)
    {
        abort_unless(
            $r->isSecure() ||
                (config('api.allow_loopback_http', false) &&
                    in_array($r->ip(), ['127.0.0.1', '::1'], true) &&
                    in_array($r->getHost(), ['127.0.0.1', 'localhost', '::1'], true)),
            400,
            'HTTPS erforderlich.',
        );
        abort_if(strlen($r->getContent()) > 1048576, 413);
        abort_if(
            $r->headers->has('Origin'),
            403,
            'Browserzugriff auf die Maschinen-API ist nicht freigegeben.',
        );
        $ip = 'api-ip:' . hash('sha256', $r->ip());
        abort_if($this->limits->tooManyAttempts($ip, 300), 429);
        $this->limits->hit($ip, 60);
        $credential = $r->bearerToken();
        abort_unless(
            is_string($credential) && preg_match('/^ph_([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $credential, $m),
            401,
            'Ungültiger API-Zugang.',
        );
        $token = $this->db->table('api_tokens')->find($m[1]);
        abort_unless(
            $token &&
                !$token->revoked_at &&
                $token->expires_at > now()->toDateTimeString() &&
                hash_equals($token->secret_hash, hash('sha256', $m[2])),
            401,
            'Ungültiger API-Zugang.',
        );
        $guard = $this->auth->guard('web');
        $user = $guard->getProvider()->retrieveById($token->user_id);
        abort_unless(
            $user &&
                $user->active &&
                $user->canManage() &&
                (string) $user->tenant_id === (string) $token->tenant_id,
            401,
            'Ungültiger API-Zugang.',
        );
        // Check tenant lifecycle even for shared resources which do not open a tenant database.
        if ($user->tenant_id) {
            abort_unless(
                $this->db
                    ->table('tenants')
                    ->where('id', $user->tenant_id)
                    ->where('status', 'active')
                    ->exists(),
                403,
            );
        }
        foreach (['tenant_id', 'X-Tenant-ID'] as $key) {
            $value = $key === 'tenant_id' ? $r->input($key) : $r->header($key);
            if ($value !== null && !$user->isSystem()) {
                abort_unless((string) $value === (string) $token->tenant_id, 403, 'Abweichender Mandant.');
            }
        }
        abort_if(
            $user->isSystem() && $r->header('X-Tenant-ID') !== null,
            403,
            'Plattformzugang kann keinen Restaurantkontext übernehmen.',
        );
        $cidrs = json_decode($token->cidrs, true);
        abort_if($cidrs && !IpUtils::checkIp($r->ip(), $cidrs), 403);
        $key = 'api-token:' . $token->id;
        abort_if($this->limits->tooManyAttempts($key, 120), 429);
        $this->limits->hit($key, 60);
        $settings = $this->db->table('api_settings')->where('module', 'api')->first();
        abort_if(
            $settings && (!$settings->enabled || ($token->audience === 'mcp' && !$settings->mcp_enabled)),
            403,
        );
        if ($token->audience === 'mcp') {
            $mcp = $this->db->table('api_settings')->where('module', 'mcp')->first();
            abort_if($mcp && !$mcp->enabled, 403);
        }
        $default = $this->auth->getDefaultDriver();
        $this->auth->shouldUse('web');
        $old = $r->getUserResolver();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($user);
        $r->setUserResolver(fn() => $user);
        $r->attributes->set('api.token', $token);
        $r->attributes->set('portal', $user->isSystem() ? 'administration' : 'restaurant');
        $this->db
            ->table('api_tokens')
            ->where('id', $token->id)
            ->update(['last_used_at' => now()]);
        try {
            return $next($r);
        } finally {
            $this->auth->shouldUse($default);
            $r->setUserResolver($old);
            if ($previous) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
