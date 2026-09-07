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
            '--path' => 'database/tenant',
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
}
