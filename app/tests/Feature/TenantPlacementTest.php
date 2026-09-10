<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\Totp;
use App\Jobs\{MoveTenant, ProvisionTenant};
use App\Modules\Provisioning\PublicApi\ServerDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, DB};
use Tests\TestCase;
class TenantPlacementTest extends TestCase
{
    use RefreshDatabase;
    public function test_creation_rejects_unapproved_server_and_persists_approved_assignment(): void
    {
        Queue::fake();
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Test-password-only',
            'role' => 'system_admin',
        ])->fresh();
        $directory = $this->mock(ServerDirectory::class);
        $directory->shouldReceive('isEnabled')->with(99)->andReturn(false);
        $this->actingAs($user)
            ->postJson('/api/v1/admin/tenants', [
                'name' => 'New',
                'email' => 'new@example.test',
                'timezone' => 'Europe/Berlin',
                'server_id' => 99,
            ])
            ->assertUnprocessable();
        Queue::assertNothingPushed();
    }
    public function test_move_requires_root_password_and_mfa_without_changing_placement(): void
    {
        Queue::fake();
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Test-password-only',
            'role' => 'system_admin',
        ])->fresh();
        $tenant = Tenant::create([
            'name' => 'Existing',
            'email' => 'tenant@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'test-only',
        ])->fresh();
        $data = [
            'target_server_id' => null,
            'placement_version' => 1,
            'password' => 'wrong',
            'code' => '123456',
            'backup_confirmed' => true,
            'downtime_confirmed' => true,
        ];
        $this->actingAs($user)
            ->postJson('/api/v1/admin/tenants/' . $tenant->id . '/move', $data)
            ->assertUnprocessable();
        $data['password'] = 'Test-password-only';
        $this->postJson('/api/v1/admin/tenants/' . $tenant->id . '/move', $data)->assertUnprocessable();
        $this->assertDatabaseCount('tenant_operations', 0);
        $this->assertSame('active', $tenant->fresh()->status);
        Queue::assertNothingPushed();
    }
    public function test_batch_move_checks_every_version_before_creating_any_operation(): void
    {
        Queue::fake();
        $secret = Totp::secret();
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'batch@example.test',
            'password' => 'Test-password-only',
            'role' => 'system_admin',
            'mfa_secret' => $secret,
        ])->fresh();
        $rows = [];
        foreach (['a', 'b'] as $suffix) {
            $rows[] = Tenant::create([
                'name' => 'Restaurant ' . $suffix,
                'email' => $suffix . '@example.test',
                'status' => 'active',
                'database_name' => 'ph_t_' . str_repeat($suffix, 24),
                'database_user' => 'phu_' . str_repeat($suffix, 24),
                'database_password' => 'test-only',
            ])->fresh();
        }
        $this->mock(ServerDirectory::class)->shouldReceive('isEnabled')->with(7)->andReturn(true);
        $data = [
            'target_server_id' => 7,
            'password' => 'Test-password-only',
            'code' => Totp::code($secret, intdiv(time(), 30)),
            'backup_confirmed' => true,
            'downtime_confirmed' => true,
            'tenants' => [
                ['id' => $rows[0]->id, 'placement_version' => 1],
                ['id' => $rows[1]->id, 'placement_version' => 99],
            ],
        ];
        $this->actingAs($admin)->postJson('/api/v1/admin/tenant-moves', $data)->assertConflict();
        $this->assertDatabaseCount('tenant_operations', 0);
        $this->assertSame('active', $rows[0]->fresh()->status);
        $this->assertNull($admin->fresh()->mfa_last_step);
        $data['tenants'][1]['placement_version'] = 1;
        $this->postJson('/api/v1/admin/tenant-moves', $data)
            ->assertStatus(202)
            ->assertJsonCount(2, 'operations');
        $this->assertDatabaseCount('tenant_operations', 2);
        $this->assertSame('moving', $rows[0]->fresh()->status);
        $this->assertSame('moving', $rows[1]->fresh()->status);
        $this->postJson('/api/v1/admin/tenant-moves', $data)->assertUnprocessable();
        $this->assertDatabaseCount('tenant_operations', 2);
    }
    public function test_restaurant_cannot_request_moves_or_list_operations(): void
    {
        $u = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'test-only',
            'role' => 'restaurant_admin',
        ])->fresh();
        $this->actingAs($u)->getJson('/api/v1/admin/operations')->assertForbidden();
    }
}
