<?php
namespace Tests\Feature;
use App\Models\{User, Tenant};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DatabaseAccessTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'sql-admin@example.test',
            'password' => 'Admin-test-password-2026',
            'role' => 'system_admin',
        ])->fresh();
    }
    public function test_metadata_never_contains_passwords_and_reveal_requires_reauthentication(): void
    {
        config(['database.connections.mysql.password' => 'fake-platform-secret']);
        $this->actingAs($this->admin());
        $this->getJson('/api/v1/admin/database-access')
            ->assertOk()
            ->assertJsonMissingPath('0.password')
            ->assertDontSee('fake-platform-secret');
        $this->postJson('/api/v1/admin/database-access/platform/reveal', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertDontSee('fake-platform-secret');
        $this->postJson('/api/v1/admin/database-access/platform/reveal', [
            'password' => 'Admin-test-password-2026',
        ])
            ->assertOk()
            ->assertJsonPath('password', 'fake-platform-secret')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'database.credentials_revealed',
            'resource' => 'platform',
        ]);
        $this->assertStringNotContainsString(
            'fake-platform-secret',
            json_encode(DB::table('audit_entries')->get()),
        );
    }
    public function test_restaurant_and_platform_staff_cannot_read_or_reveal_connections(): void
    {
        foreach (['staff', 'restaurant_admin', 'platform_staff'] as $role) {
            $user = User::create([
                'name' => 'Reader',
                'email' => $role . '@example.test',
                'password' => 'test',
                'role' => $role,
            ])->fresh();
            $this->actingAs($user)->getJson('/api/v1/admin/database-access')->assertForbidden();
            $this->postJson('/api/v1/admin/database-access/platform/reveal', [
                'password' => 'test',
            ])->assertForbidden();
        }
    }
    public function test_reveal_uses_selected_tenant_and_excludes_pending_databases(): void
    {
        $tenant = Tenant::create([
            'name' => 'A',
            'email' => 'a@example.test',
            'status' => 'active',
            'timezone' => 'Europe/Berlin',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'test-user',
            'database_password' => 'fake-tenant-secret',
        ]);
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/database-access/' . $tenant->id . '/reveal', [
                'password' => 'Admin-test-password-2026',
            ])
            ->assertOk()
            ->assertJsonPath('password', 'fake-tenant-secret');
        $this->getJson('/api/v1/admin/tenants')
            ->assertDontSee('fake-tenant-secret')
            ->assertDontSee('test-user');
        $tenant->update(['status' => 'pending']);
        $this->postJson('/api/v1/admin/database-access/' . $tenant->id . '/reveal', [
            'password' => 'Admin-test-password-2026',
        ])->assertNotFound();
    }
    public function test_remote_plain_http_and_inactive_admin_are_denied(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->postJson('http://example.test/api/v1/admin/database-access/platform/reveal', [
                'password' => 'Admin-test-password-2026',
            ])
            ->assertForbidden();
        $admin->update(['active' => false]);
        $this->getJson('/api/v1/admin/database-access')->assertForbidden();
    }
}
