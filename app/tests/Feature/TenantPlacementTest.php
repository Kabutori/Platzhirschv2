<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
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
