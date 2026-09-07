<?php
namespace Tests\Feature;
use App\Models\{User, Tenant};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PortalTest extends TestCase
{
    use RefreshDatabase;
    private function accounts(): array
    {
        $tenant = Tenant::create([
            'name' => 'Restaurant',
            'email' => 'owner@example.test',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'test',
            'status' => 'active',
        ]);
        $password = 'Strong-test-password-2026';
        return [
            User::create([
                'name' => 'Admin',
                'email' => 'admin@example.test',
                'password' => $password,
                'role' => 'system_admin',
            ])->fresh(),
            User::create([
                'name' => 'Owner',
                'email' => 'owner@example.test',
                'password' => $password,
                'role' => 'restaurant_admin',
                'tenant_id' => $tenant->id,
            ])->fresh(),
        ];
    }
    private function login(User $user, string $portal)
    {
        return $this->withHeader('X-Platzhirsch-Portal', $portal)->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-test-password-2026',
        ]);
    }
    public function test_accounts_cannot_log_into_the_other_portal(): void
    {
        [$admin, $owner] = $this->accounts();
        $this->login($admin, 'restaurant')->assertUnauthorized();
        $this->login($owner, 'administration')->assertUnauthorized();
        $this->login($owner, 'restaurant')->assertOk();
        $this->getJson('/api/v1/admin/tenants')->assertForbidden();
    }
    public function test_portals_keep_separate_identities_and_logout(): void
    {
        [$admin, $owner] = $this->accounts();
        $this->login($admin, 'administration')->assertOk();
        $this->withHeader('X-Platzhirsch-Portal', 'restaurant')
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
        $this->login($owner, 'restaurant')->assertOk();
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('id', $owner->id);
        $this->withHeader('X-Platzhirsch-Portal', 'administration')
            ->getJson('/api/v1/admin/auth/me')
            ->assertJsonPath('id', $admin->id);
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->withHeader('X-Platzhirsch-Portal', 'restaurant')
            ->getJson('/api/v1/admin/auth/me')
            ->assertJsonPath('id', $owner->id);
    }
    public function test_platform_portal_cannot_impersonate_a_restaurant(): void
    {
        [$admin, $owner] = $this->accounts();
        $this->login($admin, 'administration')->assertOk();
        $this->withHeader('X-Tenant-ID', (string) $owner->tenant_id)
            ->getJson('/api/v1/restaurant/profile')
            ->assertForbidden();
    }
    public function test_invalid_portal_is_rejected(): void
    {
        $this->withHeader('X-Platzhirsch-Portal', 'unexpected')->getJson('/api/csrf')->assertStatus(400);
    }
}
