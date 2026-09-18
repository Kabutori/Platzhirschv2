<?php
namespace App\Modules\Api;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class Errors
{
    public function __construct(private \Illuminate\Database\DatabaseManager $db) {}
    public function handle($r, \Closure $next)
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $r->attributes->set('api.request_id', $id);
        try {
            $response = $next($r);
        } catch (\Throwable $e) {
            $status =
                $e instanceof ValidationException
                    ? 422
                    : ($e instanceof HttpExceptionInterface
                        ? $e->getStatusCode()
                        : 500);
            $title =
                $status >= 500
                    ? 'Anfrage konnte nicht verarbeitet werden.'
                    : ($e instanceof ValidationException
                        ? 'Ungültige Eingaben.'
                        : ($e->getMessage() ?:
                        'Anfrage abgewiesen.'));
            $response = response()->json(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                    'request_id' => $id,
                    ...$e instanceof ValidationException ? ['errors' => $e->errors()] : [],
                ],
                $status,
            );
            $response->headers->set('Content-Type', 'application/problem+json');
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="Platzhirsch API"');
        }
        if ($response->getStatusCode() === 429) {
            $response->headers->set('Retry-After', '60');
        }
        if ($token = $r->attributes->get('api.token')) {
            $this->db
                ->table('api_access_events')
                ->insert([
                    'request_id' => $id,
                    'token_id' => $token->id,
                    'operation' => $r->attributes->get('api.operation', 'api.discovery'),
                    'http_status' => $response->getStatusCode(),
                    'created_at' => now(),
                ]);
        }
        return $response;
    }
}
