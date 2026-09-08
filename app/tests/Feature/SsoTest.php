<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use App\Models\User;
use App\Modules\Identity\Application\{OpenIdProvider, Totp};
class SsoTest extends TestCase
{
    use RefreshDatabase;
    private \OpenSSLAsymmetricKey $key;
    private array $jwk;
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://app.example.test',
            'sso.enabled' => true,
            'sso.client_id' => 'test-client',
            'sso.client_secret' => 'test-only-secret',
            'sso.issuer' => 'https://id.example.test/realm',
            'sso.authorization_url' => 'https://id.example.test/auth',
            'sso.token_url' => 'https://id.example.test/token',
            'sso.jwks_url' => 'https://id.example.test/keys',
        ]);
        $this->key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $parts = openssl_pkey_get_details($this->key)['rsa'];
        $this->jwk = [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'kid' => 'test-key',
            'use' => 'sig',
            'n' => OpenIdProvider::encode($parts['n']),
            'e' => OpenIdProvider::encode($parts['e']),
        ];
        Http::preventStrayRequests();
    }
    private function claims(string $nonce): array
    {
        return [
            'iss' => config('sso.issuer'),
            'aud' => 'test-client',
            'sub' => 'subject-1',
            'nonce' => $nonce,
            'exp' => time() + 300,
            'iat' => time(),
        ];
    }
    private function token(array $claims, array $header = []): string
    {
        $a = OpenIdProvider::encode(json_encode([...['alg' => 'RS256', 'kid' => 'test-key'], ...$header]));
        $b = OpenIdProvider::encode(json_encode($claims));
        openssl_sign($a . '.' . $b, $signature, $this->key, OPENSSL_ALGO_SHA256);
        return $a . '.' . $b . '.' . OpenIdProvider::encode($signature);
    }
    private function start(
        string $portal = 'administration',
        string $action = 'start',
        array $data = [],
    ): array {
        $url = $this->withHeader('X-Platzhirsch-Portal', $portal)
            ->postJson('/api/v1/admin/auth/sso/' . $action, $data)
            ->assertOk()
            ->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayNotHasKey('client_secret', $query);
        return $query;
    }
    private function provider(array $query, array $extra = []): void
    {
        Http::fake([
            'https://id.example.test/token' => Http::response([
                'id_token' => $this->token([...$this->claims($query['nonce']), ...$extra]),
            ]),
            'https://id.example.test/keys' => Http::response(['keys' => [$this->jwk]]),
        ]);
    }
    private function callback(array $query, string $portal = 'administration')
    {
        $this->flushHeaders();
        return $this->get(
            '/api/v1/admin/auth/sso/callback/' .
                $portal .
                '?' .
                http_build_query(['state' => $query['state'], 'code' => 'one-time-code']),
        );
    }
    public function test_id_token_signature_and_security_claims_are_validated(): void
    {
        $p = app(OpenIdProvider::class);
        $claims = $this->claims('nonce');
        $this->assertSame('subject-1', $p->verify($this->token($claims), [$this->jwk], 'nonce'));
        foreach (
            [
                ['iss' => 'https://other.example'],
                ['aud' => 'other'],
                ['nonce' => 'wrong'],
                ['exp' => time() - 100],
                ['iat' => time() + 100],
                ['aud' => ['test-client', 'other'], 'azp' => 'other'],
                ['sub' => ''],
            ]
            as $change
        ) {
            try {
                $p->verify($this->token([...$claims, ...$change]), [$this->jwk], 'nonce');
                $this->fail('Invalid claims accepted');
            } catch (\RuntimeException) {
            }
        }
        foreach (['none', 'HS256', 'ES256'] as $alg) {
            try {
                $p->verify($this->token($claims, ['alg' => $alg]), [$this->jwk], 'nonce');
                $this->fail('Invalid algorithm accepted');
            } catch (\RuntimeException) {
            }
        }
        $jwt = $this->token($claims);
        $parts = explode('.', $jwt);
        $parts[1] = OpenIdProvider::encode(json_encode([...$claims, 'sub' => 'attacker']));
        try {
            $p->verify(implode('.', $parts), [$this->jwk], 'nonce');
            $this->fail('Invalid signature accepted');
        } catch (\RuntimeException) {
        }
    }
    public function test_sso_requires_explicit_link_and_rejects_state_replay(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'long-test-password',
            'role' => 'system_admin',
        ])->fresh();
        $q = $this->start();
        $this->provider($q, ['email' => $user->email]);
        $this->callback($q)->assertRedirect('/administration/login');
        $this->assertGuest('administration');
        DB::table('identity_sso_accounts')->insert([
            'user_id' => $user->id,
            'provider' => app(OpenIdProvider::class)->key(),
            'subject_hash' => hash('sha256', 'subject-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $q = $this->start();
        $this->provider($q);
        $this->callback($q)->assertRedirect('/administration/login');
        $this->withHeader('X-Platzhirsch-Portal', 'administration')
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
        $before = count(Http::recorded());
        $this->callback($q)->assertRedirect('/administration/login');
        $this->assertCount($before, Http::recorded());
    }
    public function test_wrong_state_never_calls_provider_and_portals_do_not_mix(): void
    {
        $q = $this->start();
        $q['state'] = 'wrong';
        $this->callback($q)->assertRedirect('/administration/login');
        Http::assertNothingSent();
        $this->assertGuest('administration');
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'test',
            'role' => 'system_admin',
        ])->fresh();
        DB::table('identity_sso_accounts')->insert([
            'user_id' => $user->id,
            'provider' => app(OpenIdProvider::class)->key(),
            'subject_hash' => hash('sha256', 'subject-1'),
        ]);
        $q = $this->start('restaurant');
        $this->provider($q);
        $this->callback($q, 'restaurant')->assertRedirect('/restaurant/login');
        $this->assertGuest('restaurant');
    }
    public function test_link_requires_password_and_sso_login_keeps_local_mfa(): void
    {
        $secret = Totp::secret();
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'long-test-password',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($user, 'administration')
            ->withHeader('X-Platzhirsch-Portal', 'administration')
            ->postJson('/api/v1/admin/auth/sso/link', ['password' => 'wrong'])
            ->assertForbidden();
        $q = $this->start('administration', 'link', ['password' => 'long-test-password']);
        $this->provider($q);
        $this->callback($q)->assertRedirect('/administration/login#account');
        $this->assertSame(1, DB::table('identity_sso_accounts')->count());
        $user->update(['mfa_secret' => $secret]);
        auth('administration')->logout();
        $q = $this->start();
        $this->provider($q);
        $this->callback($q)->assertRedirect('/administration/login');
        $this->assertGuest('administration');
        $this->withHeader('X-Platzhirsch-Portal', 'administration')
            ->getJson('/api/v1/admin/auth/sso/status')
            ->assertOk()
            ->assertJsonPath('pending_mfa', true);
        $this->postJson('/api/v1/admin/auth/sso/mfa', [
            'mfa_code' => Totp::code($secret, intdiv(time(), 30)),
        ])->assertOk();
        $this->assertAuthenticatedAs($user, 'administration');
    }
}
