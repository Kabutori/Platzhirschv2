<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Artisan};
use App\Models\{User, Tenant};
use App\Services\TenantDatabase;
use App\Modules\Api\Catalog;
class ExternalApiTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $owner;
    private Tenant $tenant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://localhost']);
        $this->withServerVariables(['HTTPS' => 'on']);
        $this->tenant = Tenant::create([
            'name' => 'API Test',
            'email' => 'owner@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('c', 24),
            'database_user' => 'phu_' . str_repeat('c', 24),
            'database_password' => 'test-only',
        ]);
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'api-admin@example.test',
            'password' => 'Test-password-123',
            'role' => 'system_admin',
        ])->fresh();
        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'api-owner@example.test',
            'password' => 'Test-password-123',
            'role' => 'restaurant_admin',
            'tenant_id' => $this->tenant->id,
        ])->fresh();
        config(['database.connections.tenant' => config('database.connections.sqlite')]);
        DB::purge('tenant');
        foreach (['reservation', 'reporting', 'weather', 'notification'] as $module) {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => app(\App\Core\Module\ModuleRegistry::class)->get($module)->tenantMigrationsPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
        }
        $this->mock(TenantDatabase::class, function ($m) {
            $m->shouldReceive('connect')->andReturnNull();
            $m->shouldReceive('disconnect')->andReturnNull();
        });
    }
    private function token(User $user, array $scopes, string $audience = 'api'): string
    {
        return $this->actingAs($user)
            ->postJson('https://localhost/api/v1/access/tokens', [
                'name' => 'Fixture',
                'audience' => $audience,
                'scopes' => $scopes,
                'days' => 30,
                'cidrs' => [],
                'password' => 'Test-password-123',
                'confirmed' => true,
            ])
            ->assertOk()
            ->json('token');
    }
    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        $file = getenv('PLATZHIRSCH_API_OBSERVATIONS');
        if (
            $file &&
            str_contains($uri, '/api/external/v1/') &&
            $response->getStatusCode() < 300 &&
            str_contains($response->headers->get('Content-Type', ''), 'application/json')
        ) {
            $path = parse_url($uri, PHP_URL_PATH);
            if (str_ends_with($path, '/openapi.json')) {
                file_put_contents(
                    $file,
                    json_encode(
                        ['operation' => '@openapi', 'body' => json_decode($response->getContent())],
                        JSON_THROW_ON_ERROR,
                    ) . "\n",
                    FILE_APPEND | LOCK_EX,
                );
                return $response;
            }
            foreach (app(Catalog::class)->all() as $op) {
                $prefix = '/api/external/v1/' . $op['module'] . '/';
                if ($op['method'] !== $method || !str_starts_with($path, $prefix)) {
                    continue;
                }
                $request = \Illuminate\Http\Request::create(
                    '/api/v1/' . substr($path, strlen($prefix)),
                    $method,
                );
                if (!$op['route']->matches($request)) {
                    continue;
                }
                file_put_contents(
                    $file,
                    json_encode(
                        [
                            'operation' => $op['id'],
                            'status' => (string) $response->getStatusCode(),
                            'body' => json_decode($response->getContent()),
                        ],
                        JSON_THROW_ON_ERROR,
                    ) . "\n",
                    FILE_APPEND | LOCK_EX,
                );
                break;
            }
        }
        return $response;
    }
    public function test_read_contracts_cover_both_portals_and_concrete_route_variants(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->mock(TenantDatabase::class, function ($m) {
            $m->shouldReceive('connect', 'disconnect')->andReturnNull();
            $m->shouldReceive('configuration')->andReturn(['host' => 'db.example.test', 'port' => '3306']);
        });
        foreach ([$this->admin, $this->owner] as $user) {
            $this->flushHeaders();
            $token = $this->token($user, app(Catalog::class)->scopes($user));
            $this->bearer($token);
            $this->getJson('https://localhost/api/external/v1/openapi.json')->assertOk();
            foreach (app(Catalog::class)->manageable($user) as $op) {
                if ($op['method'] !== 'GET') {
                    continue;
                }
                $path = $op['path'];
                $skip = false;
                foreach ($op['parameters'] as $name) {
                    $values = $op['contract']['parameters']['properties'][$name]['enum'] ?? null;
                    if (!$values) {
                        $skip = true;
                        break;
                    }
                    $path = str_replace('{' . $name . '}', rawurlencode($values[0]), $path);
                }
                if ($skip) {
                    continue;
                }
                $query = http_build_query($op['contract']['example']['query']);
                $response = $this->getJson('https://localhost' . $path . ($query ? '?' . $query : ''));
                $this->assertContains(
                    $response->getStatusCode(),
                    [200, 403, 422],
                    $op['id'] . ' ' . $response->getContent(),
                );
            }
        }
    }
    public function test_tokens_are_hashed_once_scoped_and_revoked_immediately(): void
    {
        $token = $this->token($this->owner, ['support:read']);
        $id = DB::table('api_tokens')->value('id');
        $this->assertStringNotContainsString(
            substr($token, -64),
            DB::table('api_tokens')->value('secret_hash'),
        );
        $this->getJson('https://localhost/api/v1/access')
            ->assertOk()
            ->assertJsonMissingPath('tokens.0.secret_hash')
            ->assertDontSee($token, false);
        $this->bearer($token)->getJson('https://localhost/api/external/v1/support/support')->assertOk();
        $this->postJson('https://localhost/api/external/v1/support/support', [])->assertForbidden();
        $this->getJson('https://localhost/api/external/v1/identity/admin/users')->assertForbidden();
        $this->postJson('https://localhost/api/v1/access/tokens/' . $id . '/revoke', [
            'password' => 'Test-password-123',
            'confirmed' => true,
        ])->assertOk();
        $this->getJson('https://localhost/api/external/v1/support/support')->assertUnauthorized();
    }
    public function test_scope_tenant_and_owner_changes_do_not_grant_access(): void
    {
        $token = $this->token($this->owner, ['reservation:read', 'support:read']);
        $this->bearer($token);
        $this->getJson('https://localhost/api/external/v1/reservation/restaurant/rooms')->assertOk();
        $this->withHeader('X-Tenant-ID', '999')
            ->getJson('https://localhost/api/external/v1/reservation/restaurant/rooms')
            ->assertForbidden();
        $this->flushHeaders();
        $this->bearer($token);
        $this->getJson('https://localhost/api/external/v1/support/support?tenant_id=999')->assertForbidden();
        DB::table('users')
            ->where('id', $this->owner->id)
            ->update(['role' => 'staff']);
        $this->getJson('https://localhost/api/external/v1/support/support')->assertUnauthorized();
    }
    public function test_cross_tenant_records_and_expired_paid_modules_are_hidden(): void
    {
        $token = $this->token($this->owner, ['support:read', 'reporting:read']);
        $this->bearer($token);
        $id = DB::table('support_tickets')->insertGetId([
            'tenant_id' => 999,
            'user_id' => $this->owner->id,
            'subject' => 'FOREIGN',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson('https://localhost/api/external/v1/support/support/' . $id)->assertNotFound();
        $this->getJson('https://localhost/api/external/v1/support/support')
            ->assertOk()
            ->assertDontSee('FOREIGN');
        $url =
            'https://localhost/api/external/v1/reporting/restaurant/reporting?from=2026-09-17&to=2026-09-17';
        $this->getJson($url)->assertForbidden();
        DB::table('billing_entitlements')->insert([
            'tenant_id' => $this->tenant->id,
            'module_code' => 'reporting',
            'paid_until' => now()->addMonth(),
            'status' => 'active',
        ]);
        $this->getJson($url)->assertOk();
        DB::table('billing_entitlements')->update(['paid_until' => now()->subDay()]);
        $this->getJson($url)->assertForbidden();
    }
    public function test_idempotency_runs_a_write_once_and_rejects_changed_payload(): void
    {
        $token = $this->token($this->owner, ['support:write']);
        $this->bearer($token);
        $body = ['subject' => 'Anfrage', 'body' => 'Test', 'priority' => 'normal'];
        $url = 'https://localhost/api/external/v1/support/support';
        $this->postJson($url, $body)->assertUnprocessable();
        $key = (string) \Illuminate\Support\Str::uuid();
        $this->withHeader('Idempotency-Key', $key);
        $id = $this->postJson($url, $body)->assertCreated()->json('id');
        $this->postJson($url, $body)
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson($url, [...$body, 'subject' => 'Geändert'])->assertConflict();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseHas('api_access_events', ['http_status' => 409]);
    }
    public function test_mcp_write_requires_human_confirmation_bound_to_exact_payload(): void
    {
        $token = $this->token($this->owner, ['support:read', 'support:write'], 'mcp');
        $this->bearer($token);
        $op = collect(app(Catalog::class)->all())->first(
            fn($o) => $o['uri'] === 'api/v1/support' && $o['method'] === 'POST',
        );
        $body = ['subject' => 'MCP Anfrage', 'body' => 'Test', 'priority' => 'normal'];
        $url = 'https://localhost/api/external/v1/support/support';
        $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson($url, $body)
            ->assertStatus(428);
        $id = $this->postJson('https://localhost/api/external/v1/confirmations', [
            'operation' => $op['id'],
            'parameters' => [],
            'query' => [],
            'body' => $body,
        ])
            ->assertOk()
            ->json('id');
        $this->withHeader('X-Api-Confirmation', $id)->postJson($url, $body)->assertStatus(428);
        $this->postJson('https://localhost/api/v1/access/confirmations/' . $id . '/approve', [
            'password' => 'wrong',
            'confirmed' => true,
        ])->assertForbidden();
        $this->postJson('https://localhost/api/v1/access/confirmations/' . $id . '/approve', [
            'password' => 'Test-password-123',
            'confirmed' => true,
        ])->assertOk();
        $this->postJson($url, [...$body, 'subject' => 'Andere Aktion'])->assertStatus(428);
        $this->postJson($url, $body)->assertCreated();
        $this->postJson($url, $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson($url, $body)
            ->assertStatus(428);
        $this->assertDatabaseCount('support_tickets', 1);
    }
    public function test_module_switches_expiry_ip_and_origin_are_enforced(): void
    {
        $token = $this->token($this->owner, ['support:read']);
        $this->bearer($token);
        DB::table('api_settings')->insert(['module' => 'support', 'enabled' => false]);
        $this->getJson('https://localhost/api/external/v1/support/support')->assertForbidden();
        DB::table('api_settings')->delete();
        DB::table('api_tokens')->update(['cidrs' => '["192.0.2.1/32"]']);
        $this->getJson('https://localhost/api/external/v1/catalog')->assertForbidden();
        DB::table('api_tokens')->update(['cidrs' => '[]']);
        $this->withHeader('Origin', 'https://evil.example')
            ->getJson('https://localhost/api/external/v1/catalog')
            ->assertForbidden();
        $this->flushHeaders();
        $this->bearer($token);
        DB::table('api_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('https://localhost/api/external/v1/catalog')->assertUnauthorized();
    }
    public function test_api_does_not_accept_cookie_only_authentication_or_plain_http(): void
    {
        $this->actingAs($this->admin)
            ->getJson('https://localhost/api/external/v1/catalog')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="Platzhirsch API"');
        $token = $this->token($this->admin, ['audit:read']);
        $this->bearer($token)
            ->withServerVariables(['HTTPS' => 'off'])
            ->getJson('http://localhost/api/external/v1/catalog')
            ->assertStatus(400);
    }
    public function test_discovery_openapi_and_every_installed_module_contract(): void
    {
        $token = $this->token($this->admin, [
            'audit:read',
            'provisioning:read',
            'identity:read',
            'integration-odoo:read',
        ]);
        $this->bearer($token);
        $ops = $this->getJson('https://localhost/api/external/v1/catalog')->assertOk()->json('operations');
        $this->assertNotEmpty($ops);
        foreach ($ops as $op) {
            $this->assertSame('GET', $op['method']);
            $this->assertNotSame('restaurant', $op['context']);
        }
        $this->getJson('https://localhost/api/external/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0');
        $this->getJson('https://localhost/api/external/v1/audit/admin/audit-log')->assertOk();
        $this->getJson('https://localhost/api/external/v1/provisioning/admin/database-servers')->assertOk();
        $this->getJson('https://localhost/api/external/v1/identity/admin/users')->assertOk();
        $this->getJson('https://localhost/api/external/v1/integration-odoo/admin/integrations/odoo')
            ->assertOk()
            ->assertJsonPath('state', 'placeholder');
    }
    public function test_remaining_module_reads_and_weather_write_use_real_controllers(): void
    {
        $token = $this->token($this->admin, [
            'billing:read',
            'customer:read',
            'release:read',
            'platform:read',
        ]);
        $this->bearer($token);
        foreach (
            [
                'billing/admin/billing',
                'customer/admin/tenants',
                'release/releases',
                'platform/admin/dashboard',
            ]
            as $path
        ) {
            $this->getJson('https://localhost/api/external/v1/' . $path)->assertOk();
        }
        $this->flushHeaders();
        $token = $this->token($this->owner, [
            'notification:read',
            'widget:read',
            'weather:read',
            'weather:write',
        ]);
        $this->bearer($token);
        DB::table('billing_entitlements')->insert([
            'tenant_id' => $this->tenant->id,
            'module_code' => 'weather',
            'paid_until' => now()->addMonth(),
            'status' => 'active',
        ]);
        foreach (
            [
                'notification/restaurant/notifications',
                'widget/restaurant/widget',
                'weather/restaurant/weather/settings',
            ]
            as $path
        ) {
            $this->getJson('https://localhost/api/external/v1/' . $path)->assertOk();
        }
        $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->putJson('https://localhost/api/external/v1/weather/restaurant/weather/settings', [
                'enabled' => true,
                'latitude' => 52.5,
                'longitude' => 13.4,
                'mode' => 'evaluation',
                'rain_threshold' => 50,
                'version' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);
        $this->assertSame(1, (int) DB::connection('tenant')->table('weather_settings')->value('version'));
    }
    public function test_portal_token_management_and_mcp_switch_cannot_be_bypassed(): void
    {
        $token = $this->token($this->owner, ['support:read'], 'mcp');
        $this->bearer($token);
        DB::table('api_settings')->insert(['module' => 'mcp', 'enabled' => false]);
        $this->getJson('https://localhost/api/external/v1/catalog')->assertForbidden();
        DB::table('api_settings')->delete();
        $this->putJson('https://localhost/api/v1/access/modules/api', [
            'enabled' => false,
            'mcp_enabled' => false,
            'revision' => 0,
            'password' => 'Test-password-123',
            'confirmed' => true,
        ])->assertForbidden();
        $this->postJson('https://localhost/api/v1/access/tokens', [
            'password' => 'incorrect',
            'confirmed' => true,
        ])->assertForbidden();
        $this->flushHeaders();
        $this->actingAs($this->admin)
            ->postJson(
                'https://localhost/api/v1/access/tokens/' . DB::table('api_tokens')->value('id') . '/revoke',
                ['password' => 'Test-password-123', 'confirmed' => true],
            )
            ->assertNotFound();
        $this->bearer($token)->getJson('https://localhost/api/external/v1/catalog')->assertOk();
    }
    public function test_role_rollout_preview_works_without_browser_session_and_is_token_bound(): void
    {
        $token = $this->token($this->admin, ['identity:write']);
        $this->bearer($token);
        $body = [
            'name' => 'API-Rolle',
            'permissions' => ['reservation.read'],
            'tenant_ids' => [$this->tenant->id],
        ];
        $confirmation = $this->postJson('https://localhost/api/external/v1/confirmations', [
            'operation' => 'identity.post.admin_role_rollout_preview',
            'parameters' => [],
            'query' => [],
            'body' => $body,
        ])
            ->assertOk()
            ->json('id');
        $this->postJson('https://localhost/api/v1/access/confirmations/' . $confirmation . '/approve', [
            'password' => 'Test-password-123',
            'confirmed' => true,
        ])->assertOk();
        $preview = $this->withHeaders([
            'X-Api-Confirmation' => $confirmation,
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ])
            ->postJson('https://localhost/api/external/v1/identity/admin/role-rollout/preview', $body)
            ->assertOk()
            ->json();
        $this->assertSame('API-Rolle', $preview['preview']['name']);
        $cache = app(\Illuminate\Contracts\Cache\Factory::class)->store();
        $id = DB::table('api_tokens')->value('id');
        $this->assertSame(
            $preview['token'],
            $cache->get('identity:rollout:' . $this->admin->id . ':' . $id)['token'],
        );
        $this->assertNull($cache->get('identity:rollout:' . $this->admin->id . ':other-token'));
    }
}
