<?php
namespace App\Modules\Api;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Str;
class Gateway
{
    public function __construct(
        private Catalog $catalog,
        private Router $router,
        private DatabaseManager $db,
        private Encrypter $crypt,
    ) {}
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map([self::class, 'canonical'], $value);
    }
    public static function fingerprint(
        string $operation,
        array $parameters,
        array $query,
        array $body,
    ): string {
        return hash(
            'sha256',
            json_encode(self::canonical([$operation, $parameters, $query, $body]), JSON_THROW_ON_ERROR),
        );
    }
    public function catalog(Request $r): array
    {
        return [
            'version' => '1.0.0',
            'audience' => $r->attributes->get('api.token')->audience,
            'operations' => $this->catalog->visible($r->attributes->get('api.token'), $r->user()),
        ];
    }
    public function confirmation(Request $r): array
    {
        $d = $r->validate([
            'operation' => 'required|string|max:200',
            'parameters' => 'present|array',
            'parameters.*' => 'string',
            'query' => 'present|array',
            'body' => 'present|array',
        ]);
        $token = $r->attributes->get('api.token');
        $op = $this->catalog->all()[$d['operation']] ?? null;
        abort_unless($op && $this->catalog->allowed($op, $token, $r->user()), 403);
        abort_if($op['method'] === 'GET', 422);
        $id = (string) Str::uuid();
        $hash = self::fingerprint($op['id'], $d['parameters'], $d['query'], $d['body']);
        $redact = function ($value) use (&$redact) {
            if (!is_array($value)) {
                return $value;
            }
            foreach ($value as $k => $v) {
                $value[$k] = preg_match('/password|secret|token|api.?key|credential/i', (string) $k)
                    ? '[ausgeblendet]'
                    : $redact($v);
            }
            return $value;
        };
        $this->db
            ->table('api_confirmations')
            ->insert([
                'id' => $id,
                'token_id' => $token->id,
                'user_id' => $r->user()->id,
                'operation' => $op['id'],
                'request_hash' => $hash,
                'preview' => json_encode($redact($d), JSON_THROW_ON_ERROR),
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        return [
            'id' => $id,
            'status' => 'pending',
            'message' => 'Im Portal unter API-Zugänge prüfen und bestätigen.',
            'expires_in' => 600,
        ];
    }
    public function invoke(Request $r, string $module, string $path = '')
    {
        $candidate = Request::create('/api/v1/' . $path, $r->method());
        $op = null;
        $route = null;
        foreach ($this->catalog->all() as $item) {
            if ($item['module'] === $module && $item['route']->matches($candidate)) {
                $op = $item;
                $route = clone $item['route'];
                break;
            }
        }
        abort_unless($op && $route, 404);
        $r->attributes->set('api.operation', $op['id']);
        $token = $r->attributes->get('api.token');
        abort_unless(
            $this->catalog->allowed($op, $token, $r->user()),
            403,
            'Operation ist für diesen Zugang nicht freigegeben.',
        );
        $route->bind($candidate);
        $parameters = $route->parameters();
        $query = $r->query->all();
        $body = $r->isJson() ? $r->json()->all() : $r->request->all();
        $hash = self::fingerprint($op['id'], $parameters, $query, $body);
        $requestId = null;
        if (!in_array($r->method(), ['GET', 'HEAD'], true)) {
            abort_unless($r->isJson(), 415, 'JSON erforderlich.');
            $key = $r->header('Idempotency-Key');
            abort_unless(is_string($key) && Str::isUuid($key), 422, 'Idempotency-Key als UUID erforderlich.');
            $existing = $this->db
                ->table('api_requests')
                ->where('token_id', $token->id)
                ->where('request_key', $key)
                ->first();
            if ($existing) {
                abort_unless(
                    hash_equals($existing->request_hash, $hash),
                    409,
                    'Idempotency-Key gehört zu einer anderen Anfrage.',
                );
                abort_if(
                    $existing->expires_at <= now()->toDateTimeString(),
                    410,
                    'Idempotenzfenster abgelaufen. Ergebnis vor neuer Anfrage prüfen.',
                );
                abort_unless(
                    $existing->status === 'completed',
                    409,
                    'Anfrage läuft oder ihr Ergebnis ist unklar. Vor Wiederholung prüfen.',
                );
                $cached = json_decode(
                    $this->crypt->decryptString($existing->response),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                return response($cached['body'], $existing->http_status, [
                    'Content-Type' => $cached['type'],
                    'Idempotent-Replayed' => 'true',
                ]);
            }
            $requestId = $this->db->transaction(function () use ($r, $op, $token, $key, $hash) {
                if ($op['confirmation'] || $token->audience === 'mcp') {
                    $confirmation = $this->db
                        ->table('api_confirmations')
                        ->where('id', $r->header('X-Api-Confirmation', ''))
                        ->lockForUpdate()
                        ->first();
                    abort_unless(
                        $confirmation &&
                            $confirmation->token_id === $token->id &&
                            $confirmation->approved_at &&
                            !$confirmation->used_at &&
                            $confirmation->expires_at > now()->toDateTimeString() &&
                            hash_equals($confirmation->request_hash, $hash),
                        428,
                        'Bestätigung im Portal fehlt oder ist abgelaufen.',
                    );
                    $this->db
                        ->table('api_confirmations')
                        ->where('id', $confirmation->id)
                        ->update(['used_at' => now()]);
                }
                // Unique DB constraint claims the request before any tenant/provider side effect.
                $inserted = $this->db
                    ->table('api_requests')
                    ->insertOrIgnore([
                        'token_id' => $token->id,
                        'request_key' => $key,
                        'request_hash' => $hash,
                        'status' => 'processing',
                        'expires_at' => now()->addDay(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                abort_unless($inserted, 409, 'Anfrage wird bereits verarbeitet.');
                return $this->db
                    ->table('api_requests')
                    ->where('token_id', $token->id)
                    ->where('request_key', $key)
                    ->value('id');
            });
        }
        $status = 500;
        $oldRoute = $r->getRouteResolver();
        try {
            $r->attributes->set(
                'api.original_path',
                preg_replace_callback(
                    '/\{([^}]+)\}/',
                    fn($m) => (string) ($parameters[$m[1]] ?? ''),
                    $op['uri'],
                ),
            );
            $r->setRouteResolver(fn() => $route);
            $middleware = array_values(
                array_filter($route->middleware(), fn($m) => !in_array($m, ['web', 'api', 'auth'], true)),
            );
            $aliases = $this->router->getMiddleware();
            $groups = $this->router->getMiddlewareGroups();
            $middleware = array_map(
                fn($m) => \Illuminate\Routing\MiddlewareNameResolver::resolve($m, $aliases, $groups),
                $middleware,
            );
            $response = (new Pipeline(app()))
                ->send($r)
                ->through($middleware)
                ->then(function ($r) use ($route) {
                    $this->router->substituteBindings($route);
                    $this->router->substituteImplicitBindings($route);
                    return Router::toResponse($r, $route->run());
                });
            $status = $response->getStatusCode();
            if ($requestId) {
                $content = $response->getContent();
                if (is_string($content) && strlen($content) <= 1048576 && $status < 500) {
                    $this->db
                        ->table('api_requests')
                        ->where('id', $requestId)
                        ->update([
                            'status' => 'completed',
                            'http_status' => $status,
                            'response' => $this->crypt->encryptString(
                                json_encode(
                                    [
                                        'body' => $content,
                                        'type' => $response->headers->get('Content-Type', 'application/json'),
                                    ],
                                    JSON_THROW_ON_ERROR,
                                ),
                            ),
                            'updated_at' => now(),
                        ]);
                } else {
                    $this->db
                        ->table('api_requests')
                        ->where('id', $requestId)
                        ->update(['status' => 'uncertain', 'updated_at' => now()]);
                }
            }
            return $response;
        } catch (\Throwable $e) {
            $status =
                $e instanceof \Illuminate\Validation\ValidationException
                    ? 422
                    : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                        ? $e->getStatusCode()
                        : 500);
            if ($requestId) {
                $this->db
                    ->table('api_requests')
                    ->where('id', $requestId)
                    ->update(['status' => 'uncertain', 'updated_at' => now()]);
            }
            throw $e;
        } finally {
            $r->setRouteResolver($oldRoute);
        }
    }
    public function openapi(Request $r): array
    {
        $paths = [];
        foreach ($this->catalog->visible($r->attributes->get('api.token'), $r->user()) as $op) {
            $parameters = array_map(
                fn($name) => [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                $op['parameters'],
            );
            if ($op['method'] !== 'GET') {
                $parameters[] = [
                    'name' => 'Idempotency-Key',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ];
            }
            if (
                $op['confirmation'] ||
                ($r->attributes->get('api.token')->audience === 'mcp' && $op['method'] !== 'GET')
            ) {
                $parameters[] = [
                    'name' => 'X-Api-Confirmation',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ];
            }
            $paths[$op['path']][strtolower($op['method'])] = [
                'operationId' => $op['id'],
                'tags' => [$op['module']],
                'summary' => $op['id'],
                'x-scope' => $op['scope'],
                'description' =>
                    'Feldvalidierung und Fachrechte entsprechen der bestehenden Portaloperation ' .
                    $op['uri'] .
                    '.',
                'parameters' => $parameters,
                'responses' => [
                    '200' => ['description' => 'Fachantwort (JSON oder Download entsprechend Operation)'],
                    'default' => ['description' => 'Validierungs-, Berechtigungs- oder Fachfehler'],
                ],
                ...$op['method'] !== 'GET'
                    ? [
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                    ]
                    : [],
            ];
        }
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Platzhirsch Modul-API', 'version' => '1.0.0'],
            'security' => [['bearerToken' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerToken' => ['type' => 'http', 'scheme' => 'bearer']],
            ],
        ];
    }
}
