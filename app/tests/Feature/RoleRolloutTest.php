<?php
namespace Tests\Feature;
use App\Models\{User, Tenant};
use App\Modules\Identity\Application\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class RoleRolloutTest extends TestCase
{
    use RefreshDatabase;
    public function test_rollout_is_explicit_single_use_and_does_not_assign_users(): void
    {
        $secret = Totp::secret();
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'long-test-password',
            'role' => 'system_admin',
            'mfa_secret' => $secret,
        ])->fresh();
        $tenants = [];
        foreach (['a', 'b'] as $key) {
            $tenants[] = Tenant::create([
                'name' => 'Restaurant ' . $key,
                'email' => $key . '@example.test',
                'status' => 'active',
                'database_name' => 'ph_t_' . str_repeat($key, 24),
                'database_user' => 'phu_' . str_repeat($key, 24),
                'database_password' => 'test-only',
            ])->id;
        }
        $this->actingAs($admin);
        $payload = [
            'name' => 'Empfang',
            'permissions' => ['reservation.read', 'waitlist.read'],
            'tenant_ids' => $tenants,
        ];
        $token = $this->postJson('/api/v1/admin/role-rollout/preview', $payload)->assertOk()->json('token');
        $this->assertSame(0, DB::table('restaurant_roles')->where('name', 'Empfang')->count());
        $approval = [
            'token' => $token,
            'password' => 'long-test-password',
            'mfa_code' => Totp::code($secret, intdiv(time(), 30)),
        ];
        $this->postJson('/api/v1/admin/role-rollout/apply', $approval)
            ->assertOk()
            ->assertJsonCount(2, 'created');
        $this->postJson('/api/v1/admin/role-rollout/apply', $approval)->assertConflict();
        $this->assertSame(2, DB::table('restaurant_roles')->where('name', 'Empfang')->count());
        $this->assertSame(0, DB::table('users')->whereNotNull('restaurant_role_id')->count());
        $this->postJson('/api/v1/admin/role-rollout/preview', $payload)->assertConflict();
        $this->postJson('/api/v1/admin/role-rollout/preview', [
            ...$payload,
            'name' => 'Forbidden',
            'permissions' => ['*'],
        ])->assertUnprocessable();
    }
    public function test_non_system_admin_cannot_preview_rollout(): void
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'test',
            'role' => 'restaurant_admin',
        ])->fresh();
        $this->actingAs($user)->postJson('/api/v1/admin/role-rollout/preview', [])->assertForbidden();
    }
}
