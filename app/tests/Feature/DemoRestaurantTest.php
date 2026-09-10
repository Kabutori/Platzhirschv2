<?php
namespace Tests\Feature;
use App\Models\User;
use App\Jobs\ProvisionTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Hash};
use Tests\TestCase;
class DemoRestaurantTest extends TestCase
{
    use RefreshDatabase;
    public function test_provisioning_runs_all_base_module_migrations_before_activation(): void
    {
        config(['database.connections.tenant' => config('database.connections.sqlite')]);
        \Illuminate\Support\Facades\DB::purge('tenant');
        $pdo = \Mockery::mock(\PDO::class);
        $pdo->shouldReceive('quote')->andReturnUsing(
            fn($value) => "'" . str_replace("'", "''", $value) . "'",
        );
        $pdo->shouldReceive('exec')->andReturn(0);
        $this->mock(
            \App\Services\ProvisioningConnection::class,
            fn($m) => $m
                ->shouldReceive('open')
                ->once()
                ->andReturn([$pdo, '127.0.0.1']),
        );
        $database = $this->mock(\App\Services\TenantDatabase::class, function ($m) {
            $m->shouldReceive('lock')->once()->andReturnNull();
            $m->shouldReceive('connect')->once()->andReturnNull();
            $m->shouldReceive('disconnect')->once()->andReturnNull();
        });
        $tenant = \App\Models\Tenant::create([
            'name' => 'Fresh tenant',
            'email' => 'fresh@example.test',
            'status' => 'provisioning',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'test-only',
        ]);
        (new ProvisionTenant($tenant->id))->handle($database);
        $this->assertSame('active', $tenant->fresh()->status);
        $schema = \Illuminate\Support\Facades\DB::connection('tenant')->getSchemaBuilder();
        foreach (
            [
                'reservations',
                'reservation_waitlist',
                'reservation_extra_tables',
                'reservation_notification_settings',
                'reservation_notifications',
            ]
            as $table
        ) {
            $this->assertTrue($schema->hasTable($table), $table . ' missing after provisioning');
        }
        $this->assertTrue($schema->hasColumn('rooms', 'icon'));
        $this->assertTrue($schema->hasColumn('dining_tables', 'layout_x'));
        // Paid modules must not become installed merely by creating a restaurant.
        $this->assertFalse($schema->hasTable('reporting_saved_reports'));
    }
    public function test_demo_creates_an_isolated_restaurant_and_hashed_owner_login(): void
    {
        Queue::fake();
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'system_admin',
        ])->fresh();
        $body = [
            'name' => 'Testrestaurant',
            'owner_name' => 'Test Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-test-password-2026',
            'password_confirmation' => 'Strong-test-password-2026',
        ];
        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/test-restaurant', $body)
            ->assertAccepted()
            ->assertJsonPath('tenant.is_demo', true)
            ->assertJsonMissingPath('password');
        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($body['password'], $owner->password));
        $this->assertSame('restaurant_admin', $owner->role);
        $this->assertSame($response->json('tenant.id'), $owner->tenant_id);
        Queue::assertPushed(ProvisionTenant::class, fn($job) => $job->tenantId === $owner->tenant_id);
        $this->postJson('/api/v1/admin/test-restaurant', $body)->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
    }
}
