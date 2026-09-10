<?php
namespace App\Modules\Identity\Application;
use Illuminate\Http\Client\Factory;
class OpenIdProvider
{
    public function __construct(private Factory $http) {}
    public function ready(): bool
    {
        if (!config('sso.enabled') || !config('sso.client_id') || !config('sso.client_secret')) {
            return false;
        }
        foreach (['issuer', 'authorization_url', 'token_url', 'jwks_url'] as $key) {
            $u = parse_url((string) config('sso.' . $key));
            if (
                !$u ||
                ($u['scheme'] ?? '') !== 'https' ||
                empty($u['host']) ||
                isset($u['user']) ||
                isset($u['pass']) ||
                isset($u['fragment']) ||
                isset($u['query'])
            ) {
                return false;
            }
        }
        $base = parse_url((string) config('app.url'));
        return $base &&
            !isset($base['query']) &&
            !isset($base['fragment']) &&
            !isset($base['user']) &&
            !isset($base['pass']) &&
            (($base['scheme'] ?? '') === 'https' ||
                (($base['scheme'] ?? '') === 'http' &&
                    in_array($base['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)));
    }
    public function key(): string
    {
        return hash(
            'sha256',
            json_encode([
                config('sso.issuer'),
                config('sso.client_id'),
                config('sso.authorization_url'),
                config('sso.token_url'),
                config('sso.jwks_url'),
            ]),
        );
    }
    public function redirectUri(string $portal): string
    {
        return rtrim(config('app.url'), '/') . '/api/v1/admin/auth/sso/callback/' . $portal;
    }
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    private function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \RuntimeException('invalid_encoding');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('invalid_encoding');
        }
        return $decoded;
    }
    public function authorization(string $portal, array $flow): string
    {
        return config('sso.authorization_url') .
            '?' .
            http_build_query(
                [
                    'response_type' => 'code',
                    'client_id' => config('sso.client_id'),
                    'redirect_uri' => $this->redirectUri($portal),
                    'scope' => 'openid profile',
                    'state' => $flow['state'],
                    'nonce' => $flow['nonce'],
                    'code_challenge' => self::encode(hash('sha256', $flow['verifier'], true)),
                    'code_challenge_method' => 'S256',
                    'prompt' => 'select_account',
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
    }
    public function subject(string $code, string $portal, array $flow): string
    {
        $r = $this->http
            ->asForm()
            ->withoutRedirecting()
            ->timeout(15)
            ->post(config('sso.token_url'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('sso.client_id'),
                'client_secret' => config('sso.client_secret'),
                'redirect_uri' => $this->redirectUri($portal),
                'code' => $code,
                'code_verifier' => $flow['verifier'],
            ]);
        if (!$r->successful() || !is_string($r->json('id_token'))) {
            throw new \RuntimeException('token_exchange_failed');
        }
        $keys = $this->http->withoutRedirecting()->timeout(15)->get(config('sso.jwks_url'));
        if (!$keys->successful() || !is_array($keys->json('keys'))) {
            throw new \RuntimeException('keys_unavailable');
        }
        return $this->verify($r->json('id_token'), $keys->json('keys'), $flow['nonce']);
    }
    private function der(int $tag, string $body): string
    {
        $length = strlen($body);
        $bytes = ltrim(pack('N', $length), "\0");
        return chr($tag) . ($length < 128 ? chr($length) : chr(128 + strlen($bytes)) . $bytes) . $body;
    }
    private function integer(string $body): string
    {
        $body = ltrim($body, "\0");
        if ($body === '') {
            $body = "\0";
        }
        if (ord($body[0]) & 128) {
            $body = "\0" . $body;
        }
        return $this->der(2, $body);
    }
    public function verify(string $jwt, array $keys, string $nonce): string
    {
        if (strlen($jwt) > 32768) {
            throw new \RuntimeException('invalid_token');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('invalid_token');
        }
        $header = json_decode($this->decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        $claims = json_decode($this->decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($header) ||
            !is_array($claims) ||
            ($header['alg'] ?? '') !== 'RS256' ||
            !is_string($header['kid'] ?? null) ||
            isset($header['crit']) ||
            isset($header['b64'])
        ) {
            throw new \RuntimeException('invalid_header');
        }
        $matching = array_values(
            array_filter(
                $keys,
                fn($k) => is_array($k) &&
                    ($k['kid'] ?? null) === $header['kid'] &&
                    ($k['kty'] ?? null) === 'RSA' &&
                    ($k['use'] ?? 'sig') === 'sig' &&
                    ($k['alg'] ?? 'RS256') === 'RS256',
            ),
        );
        if (count($keys) > 50 || count($matching) !== 1) {
            throw new \RuntimeException('unknown_key');
        }
        $k = $matching[0];
        $n = $this->decode($k['n'] ?? '');
        $e = $this->decode($k['e'] ?? '');
        if (strlen($n) < 256 || strlen($n) > 1024 || strlen($e) > 8) {
            throw new \RuntimeException('invalid_key');
        }
        $rsa = $this->der(48, $this->integer($n) . $this->integer($e));
        $spki = $this->der(48, hex2bin('300d06092a864886f70d0101010500') . $this->der(3, "\0" . $rsa));
        $pem =
            "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($spki), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
        $public = openssl_pkey_get_public($pem);
        if (!$public || (openssl_pkey_get_details($public)['bits'] ?? 0) < 2048) {
            throw new \RuntimeException('invalid_key');
        }
        if (
            openssl_verify(
                $parts[0] . '.' . $parts[1],
                $this->decode($parts[2]),
                $public,
                OPENSSL_ALGO_SHA256,
            ) !== 1
        ) {
            throw new \RuntimeException('invalid_signature');
        }
        $aud = $claims['aud'] ?? null;
        $client = config('sso.client_id');
        $now = time();
        if (
            ($claims['iss'] ?? null) !== config('sso.issuer') ||
            !(is_array($aud) ? in_array($client, $aud, true) : $aud === $client) ||
            (((is_array($aud) && count($aud) > 1) || isset($claims['azp'])) &&
                ($claims['azp'] ?? null) !== $client) ||
            !is_int($claims['exp'] ?? null) ||
            $claims['exp'] <= $now - 30 ||
            !is_int($claims['iat'] ?? null) ||
            $claims['iat'] > $now + 30 ||
            $claims['iat'] < $now - 600 ||
            (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 30)) ||
            !is_string($claims['nonce'] ?? null) ||
            !hash_equals($nonce, $claims['nonce']) ||
            !is_string($claims['sub'] ?? null) ||
            $claims['sub'] === '' ||
            strlen($claims['sub']) > 255
        ) {
            throw new \RuntimeException('invalid_claims');
        }
        return $claims['sub'];
    }
}
