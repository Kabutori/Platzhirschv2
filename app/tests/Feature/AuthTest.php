<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AuthTest extends TestCase
{
    use RefreshDatabase;
    public function test_profile_edit_requires_password_and_preserves_role(): void
    {
        $u = $this->admin()->fresh();
        $this->actingAs($u);
        $data = [
            'name' => 'Neuer Name',
            'email' => 'new@example.test',
            'current_password' => 'wrong',
            'role' => 'staff',
        ];
        $this->patchJson('/api/v1/admin/auth/profile', $data)->assertForbidden();
        $this->patchJson('/api/v1/admin/auth/profile', [
            ...$data,
            'current_password' => 'Strong-test-password-2026',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Neuer Name')
            ->assertJsonPath('role', 'system_admin')
            ->assertJsonMissingPath('password');
        $this->postJson('/api/v1/admin/auth/session/extend')
            ->assertOk()
            ->assertJsonPath('session_lifetime_seconds', 3600);
        $u->update(['active' => false]);
        $this->actingAs($u->fresh());
        $this->postJson('/api/v1/admin/auth/session/extend')->assertForbidden();
    }
    private function admin(): User
    {
        return User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'system_admin',
        ]);
    }
    public function test_bootstrap_requires_secret_and_runs_once(): void
    {
        config(['platzhirsch.bootstrap_token_hash' => hash('sha256', 'only-for-test')]);
        $payload = [
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-test-password-2026',
            'password_confirmation' => 'Strong-test-password-2026',
        ];
        $this->getJson('/api/bootstrap-status')
            ->assertOk()
            ->assertJson(['bootstrapped' => false]);
        $this->postJson('/api/bootstrap/first-admin', $payload)->assertForbidden();
        $this->withHeader('X-Setup-Token', 'only-for-test')
            ->postJson('/api/bootstrap/first-admin', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('user.password');
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->postJson('/api/bootstrap/first-admin', $payload)->assertConflict();
        $this->getJson('/api/bootstrap-status')->assertJson(['bootstrapped' => true]);
    }
    public function test_login_logout_and_blocked_accounts(): void
    {
        $user = $this->admin();
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-test-password-2026',
        ])
            ->assertOk()
            ->assertJsonMissingPath('user.password');
        $this->getJson('/api/v1/admin/auth/me')->assertOk();
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $user->update(['active' => false]);
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-test-password-2026',
        ])->assertUnauthorized();
    }
    public function test_mfa_and_replay_rejection(): void
    {
        $user = $this->admin();
        $secret = Totp::secret();
        $user->update(['mfa_secret' => $secret]);
        $payload = ['email' => $user->email, 'password' => 'Strong-test-password-2026'];
        $this->postJson('/api/v1/admin/auth/login', $payload)
            ->assertOk()
            ->assertJson(['mfa_required' => true]);
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $payload['mfa_code'] = Totp::code($secret, intdiv(time(), 30));
        $this->postJson('/api/v1/admin/auth/login', $payload)->assertOk();
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/admin/auth/login', $payload)->assertUnauthorized();
    }
    public function test_restaurant_user_cannot_access_platform(): void
    {
        $user = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'staff',
        ]);
        $this->actingAs($user)->getJson('/api/v1/admin/tenants')->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/v1/admin/users', ['role' => 'system_admin'])
            ->assertForbidden();
    }
    public function test_no_password_reset_secret_is_logged_when_smtp_is_missing(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'admin@example.test'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
    public function test_csrf_is_required_outside_test_mode(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'test',
        ])->assertStatus(419);
    }
}
