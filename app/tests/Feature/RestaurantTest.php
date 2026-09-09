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
    public function test_room_closures_block_widget_and_booking_and_protect_existing_reservations(): void
    {
        $date = now()->addDay()->format('Y-m-d');
        $closure = [
            'room_id' => 1,
            'starts_at' => $date . 'T18:00',
            'ends_at' => $date . 'T20:00',
            'reason' => 'Geschlossene Gesellschaft',
        ];
        $id = $this->postJson('/api/v1/restaurant/room-closures', $closure)->assertOk()->json('id');
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertConflict();
        $token = $this->widgetToken();
        $this->getJson(
            '/api/widget/' .
                $token .
                '/availability?' .
                http_build_query(['starts_at' => $date . 'T18:00', 'party_size' => 2]),
        )
            ->assertOk()
            ->assertJsonCount(0, 'tables');
        $this->deleteJson('/api/v1/restaurant/room-closures/' . $id)->assertNoContent();
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertCreated();
        $this->postJson('/api/v1/restaurant/room-closures', $closure)->assertConflict();
        $this->postJson('/api/v1/restaurant/room-closures', [
            ...$closure,
            'starts_at' => $date . 'T19:30',
        ])->assertOk();
        $this->user->update(['role' => 'staff']);
        $this->postJson('/api/v1/restaurant/room-closures', $closure)->assertForbidden();
    }
    public function test_saved_widget_combination_resolves_server_side_and_locks_all_members(): void
    {
        DB::connection('tenant')
            ->table('dining_tables')
            ->insert(['id' => 2, 'name' => 'Tisch 2', 'room_id' => 1, 'capacity' => 4, 'active' => true]);
        $combo = $this->postJson('/api/v1/restaurant/table-combinations', [
            'name' => 'Familientisch',
            'active' => true,
            'table_ids' => [1, 2],
        ])
            ->assertOk()
            ->json('id');
        $token = $this->widgetToken();
        $payload = [
            ...$this->payload(),
            'table_id' => -$combo,
            'party_size' => 6,
            'email' => 'guest@example.test',
            'consent' => true,
        ];
        $url =
            '/api/widget/' .
            $token .
            '/availability?' .
            http_build_query(['starts_at' => $payload['starts_at'], 'party_size' => 6]);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'tables')
            ->assertJsonPath('tables.0.id', -$combo);
        $id = $this->postJson('/api/widget/' . $token, $payload)
            ->assertOk()
            ->json('id');
        $this->postJson('/api/widget/' . $token, $payload)
            ->assertOk()
            ->assertJsonPath('id', $id);
        $this->assertSame(
            1,
            DB::connection('tenant')
                ->table('reservation_extra_tables')
                ->where('reservation_id', $id)
                ->count(),
        );
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'tables');
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'table_id' => 2,
        ])->assertConflict();
        $this->postJson('/api/widget/' . $token, [
            ...$payload,
            'additional_table_ids' => [2],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/restaurant/reservations/' . $id . '/cancel')->assertNoContent();
        $this->patchJson('/api/v1/restaurant/table-combinations/' . $combo, [
            'name' => 'Familientisch',
            'active' => false,
            'table_ids' => [1, 2],
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'tables');
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
    public function test_reports_separate_no_shows_cancellations_and_arrivals(): void
    {
        foreach (['12:00', '14:00', '16:00'] as $time) {
            $this->postJson('/api/v1/restaurant/reservations', $this->payload($time))->assertCreated();
        }
        $db = DB::connection('tenant');
        foreach ([1 => 'no_show', 2 => 'completed', 3 => 'cancelled'] as $id => $status) {
            $db->table('reservations')
                ->where('id', $id)
                ->update(['status' => $status]);
        }
        request()->attributes->set('tenant', $this->tenant->refresh());
        $date = now()->addDay()->format('Y-m-d');
        $rows = app(\App\Contracts\Module\ReservationReports::class)->daily($date, $date);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['reservations']);
        $this->assertSame(2, $rows[0]['guests']);
        $this->assertSame(1, $rows[0]['no_show']);
        $this->assertSame(1, $rows[0]['arrived']);
        $this->assertSame(1, $rows[0]['cancelled']);
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
            'database_name' => 'ph_t_' . str_repeat('b', 24),
            'database_user' => 'phu_' . str_repeat('b', 24),
            'database_password' => 'test-fixture-password',
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
            'database_name' => 'ph_t_' . str_repeat('b', 24),
            'database_user' => 'phu_' . str_repeat('b', 24),
            'database_password' => 'test-fixture-password',
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
        $other = Tenant::create([
            'name' => 'Other restaurant',
            'email' => 'other@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('b', 24),
            'database_user' => 'phu_' . str_repeat('b', 24),
            'database_password' => 'test-fixture-password',
        ]);
        DB::table('widget_clients')
            ->where('id', $id)
            ->update(['tenant_id' => $other->id]);
        $this->patchJson('/api/v1/restaurant/widget/' . $id, $design)->assertNotFound();
        $this->user->update(['role' => 'staff']);
        $this->patchJson('/api/v1/restaurant/widget/' . $id, $design)->assertForbidden();
    }

    public function test_overnight_opening_includes_early_next_day_and_rejects_special_day_spillover(): void
    {
        $db = DB::connection('tenant');
        $db->table('opening_hours')->delete();
        $this->postJson('/api/v1/restaurant/hours', [
            'weekday' => now()->addDay()->dayOfWeekIso,
            'opens' => '18:00',
            'closes' => '02:00',
        ])->assertOk();
        $date = now()->addDay()->format('Y-m-d');
        $payload = [...$this->payload(), 'starts_at' => $date . 'T23:30', 'duration_minutes' => 120];
        $id = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'starts_at' => now()->addDays(2)->format('Y-m-d') . 'T00:30',
        ])->assertConflict();
        $this->postJson('/api/v1/restaurant/reservations/' . $id . '/cancel')->assertNoContent();
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'starts_at' => now()->addDays(2)->format('Y-m-d') . 'T00:30',
        ])->assertCreated();
        $db->table('special_days')->insert(['date' => now()->addDays(2)->format('Y-m-d'), 'closed' => true]);
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$payload,
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertUnprocessable();
        $this->postJson('/api/v1/restaurant/hours', [
            'weekday' => 1,
            'opens' => '18:00',
            'closes' => '18:00',
        ])->assertUnprocessable();
    }
    public function test_waitlist_conversion_is_atomic_idempotent_and_permission_checked(): void
    {
        $payload = [...$this->payload(), 'requested_at' => $this->payload()['starts_at']];
        $entry = $this->postJson('/api/v1/restaurant/waitlist', $payload)->assertOk()->json('id');
        $this->postJson('/api/v1/restaurant/reservations', $this->payload())->assertCreated();
        $conversion = [
            'table_id' => 1,
            'starts_at' => $payload['starts_at'],
            'duration_minutes' => 90,
            'version' => 1,
        ];
        $this->postJson('/api/v1/restaurant/waitlist/' . $entry . '/book', $conversion)->assertConflict();
        $this->assertSame(
            'waiting',
            DB::connection('tenant')->table('reservation_waitlist')->find($entry)->status,
        );
        $conversion['starts_at'] = now()->addDay()->format('Y-m-d') . 'T20:00';
        $booking = $this->postJson('/api/v1/restaurant/waitlist/' . $entry . '/book', $conversion)
            ->assertOk()
            ->json('reservation_id');
        $this->postJson('/api/v1/restaurant/waitlist/' . $entry . '/book', $conversion)
            ->assertOk()
            ->assertJson(['reservation_id' => $booking]);
        $this->assertSame(2, DB::connection('tenant')->table('reservations')->count());
        $this->patchJson('/api/v1/restaurant/waitlist/' . $entry, [
            ...$payload,
            'version' => 1,
        ])->assertConflict();
        $role = $this->customRole(['waitlist.read']);
        $this->user->update(['role' => 'staff', 'restaurant_role_id' => $role]);
        $this->getJson('/api/v1/restaurant/waitlist?date=' . now()->addDay()->format('Y-m-d'))->assertOk();
        $this->postJson('/api/v1/restaurant/waitlist', $payload)->assertForbidden();
        $this->postJson('/api/v1/restaurant/waitlist/' . $entry . '/book', $conversion)->assertForbidden();
    }

    public function test_combination_blocks_every_member_and_releases_them_on_cancel(): void
    {
        DB::connection('tenant')
            ->table('dining_tables')
            ->insert(['id' => 2, 'name' => 'Tisch 2', 'room_id' => 1, 'capacity' => 4, 'active' => true]);
        $payload = [...$this->payload(), 'additional_table_ids' => [2], 'party_size' => 6];
        $booking = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'table_id' => 2,
        ])->assertConflict();
        $token = $this->widgetToken();
        $query = http_build_query(['starts_at' => $payload['starts_at'], 'party_size' => 2]);
        $this->getJson('/api/widget/' . $token . '/availability?' . $query)
            ->assertOk()
            ->assertJsonCount(0, 'tables');
        $this->getJson('/api/v1/restaurant/reservations?date=' . now()->addDay()->format('Y-m-d'))
            ->assertOk()
            ->assertJsonPath('0.additional_table_ids.0', 2);
        $this->postJson('/api/v1/restaurant/reservations/' . $booking . '/cancel')->assertNoContent();
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'table_id' => 2,
        ])->assertCreated();
    }
    public function test_combination_rejects_foreign_rooms_and_missing_tables(): void
    {
        DB::connection('tenant')
            ->table('rooms')
            ->insert(['id' => 2, 'name' => 'Terrasse']);
        DB::connection('tenant')
            ->table('dining_tables')
            ->insert(['id' => 2, 'name' => 'Tisch 2', 'room_id' => 2, 'capacity' => 4, 'active' => true]);
        foreach ([2, 9999] as $extra) {
            $this->postJson('/api/v1/restaurant/reservations', [
                ...$this->payload(),
                'additional_table_ids' => [$extra],
                'party_size' => 6,
            ])->assertUnprocessable();
        }
        $this->assertSame(0, DB::connection('tenant')->table('reservations')->count());
    }

    public function test_notifications_are_opt_in_and_changes_supersede_old_reminders(): void
    {
        $db = DB::connection('tenant');
        $payload = [...$this->payload(), 'email' => 'guest@example.test'];
        $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated();
        $this->assertSame(0, $db->table('reservation_notifications')->count());
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        $this->patchJson('/api/v1/restaurant/notifications', [
            'email_enabled' => true,
            'sms_enabled' => false,
            'reminder_minutes' => 120,
        ])->assertOk();
        $payload = [...$this->payload('20:00'), 'email' => 'guest@example.test'];
        $id = $this->postJson('/api/v1/restaurant/reservations', $payload)->assertCreated()->json('id');
        $this->assertSame(2, $db->table('reservation_notifications')->where('status', 'pending')->count());
        $this->postJson('/api/v1/restaurant/reservations/' . $id . '/cancel')->assertNoContent();
        $this->assertSame(1, $db->table('reservation_notifications')->where('status', 'pending')->count());
        $this->mock(
            \Illuminate\Contracts\Mail\Mailer::class,
            fn($mailer) => $mailer->shouldReceive('raw')->once()->andReturnNull(),
        );
        app(\App\Modules\Reservation\Application\ReservationNotifications::class)->dispatch(
            'Restaurant',
            'Europe/Berlin',
        );
        app(\App\Modules\Reservation\Application\ReservationNotifications::class)->dispatch(
            'Restaurant',
            'Europe/Berlin',
        );
        $this->assertSame(1, $db->table('reservation_notifications')->where('status', 'accepted')->count());
    }
    public function test_sms_rejection_is_not_retried_and_credentials_are_not_exposed(): void
    {
        config([
            'reservation_notifications.sms.sid' => 'AC' . str_repeat('a', 32),
            'reservation_notifications.sms.token' => 'unit-test-only',
            'reservation_notifications.sms.from' => '+491701234567',
        ]);
        $this->patchJson('/api/v1/restaurant/notifications', [
            'email_enabled' => false,
            'sms_enabled' => true,
            'reminder_minutes' => 0,
        ])
            ->assertOk()
            ->assertJsonMissing(['token' => 'unit-test-only']);
        \Illuminate\Support\Facades\Http::fake([
            'api.twilio.com/*' => \Illuminate\Support\Facades\Http::response([], 400),
        ]);
        $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'phone' => '+491709876543',
        ])->assertCreated();
        $service = app(\App\Modules\Reservation\Application\ReservationNotifications::class);
        $service->dispatch('Restaurant', 'Europe/Berlin');
        $service->dispatch('Restaurant', 'Europe/Berlin');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertSame(
            'rejected',
            DB::connection('tenant')->table('reservation_notifications')->first()->status,
        );
    }

    public function test_dst_missing_and_repeated_start_times_are_rejected_and_duration_is_elapsed_time(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2027-03-01', 'UTC'));
        DB::connection('tenant')
            ->table('opening_hours')
            ->update(['opens' => '00:00:00', 'closes' => '06:00:00']);
        foreach (['2027-03-28T02:30', '2027-10-31T02:30'] as $date) {
            $this->postJson('/api/v1/restaurant/reservations', [
                ...$this->payload(),
                'starts_at' => $date,
            ])->assertUnprocessable();
        }
        $r = $this->postJson('/api/v1/restaurant/reservations', [
            ...$this->payload(),
            'starts_at' => '2027-03-28T01:30',
            'duration_minutes' => 120,
        ])->assertCreated();
        $this->assertSame('2027-03-28 00:30:00', $r->json('starts_at'));
        $this->assertSame('2027-03-28 02:30:00', $r->json('ends_at'));
        $this->travelBack();
    }
}
