<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\{TenantDatabase, ReservationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Artisan};
use Tests\TestCase;
class RestaurantTest extends TestCase
{
    use RefreshDatabase;
    private Tenant $tenant;
    private User $user;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => config('database.connections.sqlite')]);
        DB::purge('tenant');
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => app(\App\Core\Module\ModuleRegistry::class)
                ->get('reservation')
                ->tenantMigrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->mock(TenantDatabase::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnNull();
            $mock->shouldReceive('disconnect')->andReturnNull();
        });
        $this->tenant = Tenant::create([
            'name' => 'Restaurant Test',
            'email' => 'test@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'not-a-production-secret',
        ]);
        $this->user = User::create([
            'name' => 'Restaurant Admin',
            'email' => 'owner@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'restaurant_admin',
            'tenant_id' => $this->tenant->id,
        ]);
        $db = DB::connection('tenant');
        $db->table('rooms')->insert([
            'id' => 1,
            'name' => 'Innenraum',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $db->table('dining_tables')->insert([
            'id' => 1,
            'name' => 'Tisch 1',
            'room_id' => 1,
            'capacity' => 4,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (range(1, 7) as $day) {
            $db->table('opening_hours')->insert([
                'weekday' => $day,
                'opens' => '12:00:00',
                'closes' => '23:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // Eloquent does not hydrate database defaults after INSERT. Authenticate
        // the persisted user just as the real login flow does (active = true).
        $this->actingAs($this->user->refresh());
    }
    private function payload(string $time = '18:00'): array
    {
        return [
            'table_id' => 1,
            'guest_name' => 'Test Guest',
            'party_size' => 2,
            'starts_at' => now()->addDay()->format('Y-m-d') . 'T' . $time,
            'duration_minutes' => 90,
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }
    public function test_reservations_overlap_but_adjacent_slots_do_not(): void
    {
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertCreated();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload('19:00'))->assertConflict();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload('19:30'))->assertCreated();
    }
    public function test_capacity_hours_and_special_days(): void
    {
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'party_size' => 5,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload('11:00'))->assertUnprocessable();
        DB::connection('tenant')
            ->table('special_days')
            ->insert([
                'date' => now()->addDay()->format('Y-m-d'),
                'closed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertUnprocessable();
    }
    public function test_idempotency_and_cancellation(): void
    {
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/restaurant/reservations', $payload)
            ->assertCreated()
            ->assertJson(['id' => $id]);
        $this->assertSame(1, DB::connection('tenant')->table('reservations')->count());
        $this->postJson('/api/v1/restaurant/reservations/' . $id . '/cancel')->assertNoContent();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertCreated();
    }
    public function test_tenant_header_cannot_override_restaurant_user(): void
    {
        $this->withHeader('X-Tenant-ID', '99999')
            ->getJson('/api/v1/restaurant/profile')
            ->assertOk()
            ->assertJson(['id' => $this->tenant->id]);
    }
    public function test_disabled_user_cannot_access_restaurant(): void
    {
        $this->user->update(['active' => false]);
        $this->getJson('/api/v1/restaurant/profile')->assertForbidden();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertForbidden();
    }
    public function test_stale_reservation_edit_is_rejected(): void
    {
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->patchJson('/api/v1/restaurant/reservations/' . $id, [
            ...$payload,
            'version' => 1,
            'guest_name' => 'Updated guest',
        ])
            ->assertOk()
            ->assertJson(['version' => 2]);
        $this->patchJson('/api/v1/restaurant/reservations/' . $id, [
            ...$payload,
            'version' => 1,
            'guest_name' => 'Stale overwrite',
        ])->assertConflict();
        $this->assertSame(
            'Updated guest',
            DB::connection('tenant')->table('reservations')->find($id)->guest_name,
        );
    }
    public function test_staff_cannot_change_configuration_or_promote_accounts(): void
    {
        $this->user->update(['role' => 'staff']);
        $this->postJson('/api/v1/restaurant/rooms', [
            'name' => 'Forbidden',
            'color' => 'sage',
            'outdoor' => false,
        ])->assertForbidden();
        $this->postJson('/api/v1/restaurant/team', [
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'system_admin',
        ])->assertForbidden();
    }
    public function test_public_widget_cannot_choose_privileged_status(): void
    {
        $token = str_repeat('b', 64);
        DB::table('widget_clients')->insert([
            'tenant_id' => $this->tenant->id,
            'token_hash' => hash('sha256', $token),
            'origins' => json_encode(['https://restaurant.example']),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withHeader('Origin', 'https://attacker.example')
            ->getJson('/api/widget/' . $token)
            ->assertForbidden();
        $this->withHeader('Origin', 'https://restaurant.example')
            ->postJson('/api/widget/' . $token, [
                ...$this->payload(),
                'email' => 'guest@example.test',
                'consent' => true,
                'status' => 'cancelled',
            ])
            ->assertOk();
        $row = DB::connection('tenant')->table('reservations')->first();
        $this->assertSame('confirmed', $row->status);
        $this->assertSame('widget', $row->source);
    }
    public function test_other_tenant_support_ticket_is_hidden(): void
    {
        $other = Tenant::create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('c', 24),
            'database_user' => 'phu_' . str_repeat('c', 24),
            'database_password' => 'test-only',
        ]);
        $id = DB::table('support_tickets')->insertGetId([
            'tenant_id' => $other->id,
            'user_id' => $this->user->id,
            'subject' => 'Private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/support/' . $id)->assertNotFound();
    }
    private function widgetToken(array $settings = []): string
    {
        $response = $this->postJson('/api/v1/restaurant/widget', [
            'origins' => ['https://restaurant.example'],
            ...$settings,
        ])->assertCreated();
        $this->assertStringContainsString('/widget.js', $response->json('embed'));
        return $response->json('token');
    }
    public function test_widget_availability_excludes_occupied_tables_and_guest_data(): void
    {
        $token = $this->widgetToken();
        $payload = $this->payload();
        $query = http_build_query(['starts_at' => $payload['starts_at'], 'party_size' => 2]);
        $this->getJson('/api/widget/' . $token . '/availability?' . $query)
            ->assertOk()
            ->assertJsonCount(1, 'tables')
            ->assertJsonMissingPath('tables.0.guest_name');
        $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated();
        $this->getJson('/api/widget/' . $token . '/availability?' . $query)
            ->assertOk()
            ->assertJsonCount(0, 'tables');
    }
    public function test_widget_duration_is_server_controlled_and_errors_have_cors_headers(): void
    {
        $token = $this->widgetToken(['duration_minutes' => 120, 'months' => 1, 'accent' => '#c08050']);
        $this->withHeader('Origin', 'https://restaurant.example')
            ->getJson('/api/widget/' . $token)
            ->assertOk()
            ->assertJson(['duration_minutes' => 120, 'accent' => '#c08050']);
        $this->postJson('/api/widget/' . $token, [
            ...$this->payload(),
            'duration_minutes' => 15,
            'email' => 'guest@example.test',
            'consent' => true,
        ])->assertOk();
        $row = DB::connection('tenant')->table('reservations')->first();
        $this->assertEquals(
            120,
            \Carbon\CarbonImmutable::parse($row->starts_at)->diffInMinutes($row->ends_at),
        );
        $this->postJson('/api/widget/' . $token, [
            ...$this->payload(),
            'email' => 'guest@example.test',
            'consent' => true,
        ])
            ->assertConflict()
            ->assertHeader('Access-Control-Allow-Origin', 'https://restaurant.example');
        $this->postJson('/api/widget/' . $token, [])
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', 'https://restaurant.example');
    }
    public function test_revoked_and_expired_widget_tokens_stop_working(): void
    {
        $token = $this->widgetToken();
        $client = DB::table('widget_clients')->where('token_hash', hash('sha256', $token))->first();
        DB::table('widget_clients')
            ->where('id', $client->id)
            ->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/widget/' . $token)->assertNotFound();
        $this->deleteJson('/api/v1/restaurant/widget/' . $client->id)->assertNoContent();
        $this->getJson('/api/widget/' . $token)->assertNotFound();
    }
    public function test_widget_preflight_obeys_the_same_origin_allowlist(): void
    {
        $token = $this->widgetToken();
        $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'Access-Control-Request-Method' => 'POST',
        ])
            ->optionsJson('/api/widget/' . $token)
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin', 'https://restaurant.example')
            ->optionsJson('/api/widget/' . $token)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://restaurant.example');
    }

    private function customRole(array $permissions): int
    {
        return $this->postJson('/api/v1/restaurant/roles', [
            'name' => 'Custom role',
            'permissions' => $permissions,
        ])
            ->assertOk()
            ->json('id');
    }
    public function test_custom_read_only_role_cannot_write_export_or_configure(): void
    {
        $id = $this->customRole(['reservation.read']);
        $this->user->update(['role' => 'staff', 'restaurant_role_id' => $id]);
        $this->getJson('/api/v1/restaurant/reservations?date=' . now()->format('Y-m-d'))->assertOk();
        $this->getJson('/api/v1/restaurant/export?date=' . now()->format('Y-m-d'))->assertForbidden();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/restaurant/rooms', [])->assertForbidden();
        $this->getJson('/api/v1/restaurant/widget')->assertForbidden();
        $this->getJson('/api/v1/restaurant/team')->assertForbidden();
        $this->getJson('/api/v1/restaurant/roles')->assertForbidden();
        $this->postJson('/api/v1/support', [])->assertForbidden();
    }
    public function test_role_cannot_grant_platform_or_team_administration(): void
    {
        foreach (['*', 'team.manage', 'roles.manage', 'system_admin'] as $permission) {
            $this->postJson('/api/v1/restaurant/roles', [
                'name' => 'Escalation',
                'permissions' => [$permission],
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/restaurant/roles', [
            'name' => 'Missing read',
            'permissions' => ['reservation.write'],
        ])->assertUnprocessable();
    }
    public function test_cancellation_right_cannot_be_bypassed_by_reservation_patch(): void
    {
        $role = $this->customRole(['reservation.read', 'reservation.write']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->user->update(['role' => 'staff', 'restaurant_role_id' => $role]);
        $this->postJson('/api/v1/restaurant/reservations/' . $id . '/cancel')->assertForbidden();
        $this->patchJson('/api/v1/restaurant/reservations/' . $id, [
            ...$payload,
            'status' => 'cancelled',
            'version' => 1,
        ])->assertForbidden();
        $this->patchJson('/api/v1/restaurant/reservations/' . $id, [
            ...$payload,
            'guest_name' => 'Allowed',
            'version' => 1,
        ])->assertOk();
    }
    public function test_role_updates_use_version_and_assigned_roles_cannot_be_deleted(): void
    {
        $id = $this->customRole(['reservation.read']);
        $this->patchJson('/api/v1/restaurant/roles/' . $id, [
            'name' => 'Reader',
            'permissions' => [],
            'version' => 1,
        ])->assertOk();
        $this->patchJson('/api/v1/restaurant/roles/' . $id, [
            'name' => 'Stale',
            'permissions' => ['reservation.read'],
            'version' => 1,
        ])->assertConflict();
        $user = $this->postJson('/api/v1/restaurant/team', [
            'name' => 'Reader',
            'email' => 'reader@example.test',
            'password' => 'Long-test-password',
            'role' => 'staff',
            'restaurant_role_id' => $id,
        ])
            ->assertCreated()
            ->json('id');
        $this->deleteJson('/api/v1/restaurant/roles/' . $id)->assertConflict();
        $this->patchJson('/api/v1/restaurant/team/' . $user, [
            'role' => 'staff',
            'restaurant_role_id' => null,
        ])->assertOk();
        $this->deleteJson('/api/v1/restaurant/roles/' . $id)->assertNoContent();
    }
    public function test_foreign_roles_cannot_be_assigned_or_modified(): void
    {
        $other = Tenant::create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'status' => 'active',
            'database_name' => 'other',
            'database_user' => 'other',
            'database_password' => 'test-only',
        ]);
        $id = DB::table('restaurant_roles')->insertGetId([
            'tenant_id' => $other->id,
            'name' => 'Other role',
            'permissions' => '["reservation.read"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/restaurant/team', [
            'name' => 'Foreign',
            'email' => 'foreign@example.test',
            'password' => 'Long-test-password',
            'role' => 'staff',
            'restaurant_role_id' => $id,
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/restaurant/roles/' . $id, [
            'name' => 'Foreign',
            'permissions' => [],
            'version' => 1,
        ])->assertNotFound();
        $this->deleteJson('/api/v1/restaurant/roles/' . $id)->assertNotFound();
        // Even corrupted data must fail closed during authorization.
        $this->user->update(['role' => 'staff', 'restaurant_role_id' => $id]);
        $this->getJson('/api/v1/restaurant/reservations?date=' . now()->format('Y-m-d'))->assertForbidden();
    }
    public function test_team_management_cannot_disable_self_or_modify_foreign_accounts(): void
    {
        $this->patchJson('/api/v1/restaurant/team/' . $this->user->id, [
            'role' => 'staff',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/restaurant/team/' . $this->user->id, [
            'role' => 'restaurant_admin',
            'active' => false,
        ])->assertUnprocessable();
        $system = User::create([
            'name' => 'System',
            'email' => 'system@example.test',
            'password' => 'Long-test-password',
            'role' => 'system_admin',
        ]);
        $this->patchJson('/api/v1/restaurant/team/' . $system->id, ['role' => 'staff'])->assertNotFound();
    }
    public function test_role_revocation_is_effective_on_next_request(): void
    {
        $id = $this->customRole(['reservation.read']);
        $this->user->update(['role' => 'staff', 'restaurant_role_id' => $id]);
        $this->getJson('/api/v1/restaurant/reservations?date=' . now()->format('Y-m-d'))->assertOk();
        DB::table('restaurant_roles')
            ->where('id', $id)
            ->update(['permissions' => '[]']);
        $this->getJson('/api/v1/restaurant/reservations?date=' . now()->format('Y-m-d'))->assertForbidden();
    }

    public function test_design_profile_and_room_table_fields_are_persisted_and_validated(): void
    {
        $this->patchJson('/api/v1/restaurant/profile', [
            'name' => 'Design Restaurant',
            'email' => 'design@example.test',
            'cuisine' => 'Regional',
            'price_range' => 'moderate',
            'description' => 'Testprofil',
            'total_seats' => 40,
            'website' => 'https://example.test',
            'logo_url' => 'https://example.test/logo.png',
        ])
            ->assertOk()
            ->assertJsonPath('cuisine', 'Regional');
        $this->patchJson('/api/v1/restaurant/profile', [
            'name' => 'Design Restaurant',
            'email' => 'design@example.test',
            'logo_url' => 'javascript:alert(1)',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo_url');
        $this->patchJson('/api/v1/restaurant/rooms/1', [
            'name' => 'Terrasse',
            'color' => 'sage',
            'outdoor' => true,
            'location' => 'Garten',
            'note' => 'Überdacht',
            'icon' => 'terrace',
        ])
            ->assertOk()
            ->assertJsonPath('location', 'Garten');
        $table = [
            'name' => 'Tisch 1',
            'room_id' => 1,
            'capacity' => 4,
            'active' => true,
            'shape' => 'round',
            'layout_x' => 25,
            'layout_y' => 70,
        ];
        $this->patchJson('/api/v1/restaurant/tables/1', $table)
            ->assertOk()
            ->assertJsonPath('shape', 'round')
            ->assertJsonPath('layout_x', 25);
        $this->patchJson('/api/v1/restaurant/tables/1', [
            ...$table,
            'layout_x' => 101,
        ])->assertUnprocessable();
    }
    public function test_widget_designer_settings_enforce_party_limit_on_availability_and_booking(): void
    {
        $token = $this->widgetToken([
            'language' => 'en',
            'position' => 'bottom-left',
            'max_party_size' => 2,
            'show_brand' => false,
        ]);
        $this->getJson('/api/widget/' . $token)
            ->assertOk()
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('position', 'bottom-left')
            ->assertJsonPath('show_brand', false);
        $payload = [
            ...$this->payload(),
            'party_size' => 3,
            'email' => 'guest@example.test',
            'consent' => true,
        ];
        $this->getJson(
            '/api/widget/' .
                $token .
                '/availability?' .
                http_build_query(['starts_at' => $payload['starts_at'], 'party_size' => 3]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_size');
        $this->postJson('/api/widget/' . $token, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_size');
        $id = DB::table('widget_clients')->where('token_hash', hash('sha256', $token))->value('id');
        $design = [
            'language' => 'de',
            'position' => 'inline',
            'max_party_size' => 4,
            'show_brand' => true,
            'duration_minutes' => 90,
            'accent' => '#c08050',
        ];
        $this->patchJson('/api/v1/restaurant/widget/' . $id, $design)->assertOk();
        $this->getJson('/api/widget/' . $token)
            ->assertJsonPath('max_party_size', 4)
            ->assertJsonPath('position', 'inline');
        $this->patchJson('/api/v1/restaurant/widget/999999', $design)->assertNotFound();
    }
}
