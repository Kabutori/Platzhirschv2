<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class PlatformRoleTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'system_admin',
        ])->fresh();
    }
    public function test_drafts_have_no_effect_until_checked_and_activated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Observer',
            'permissions' => ['platform.health.read'],
        ])
            ->assertOk()
            ->json('id');
        $this->postJson("/api/v1/admin/platform-roles/$id/activate", ['version' => 1])->assertConflict();
        $this->postJson("/api/v1/admin/platform-roles/$id/check", ['version' => 1])->assertOk();
        $this->postJson("/api/v1/admin/platform-roles/$id/activate", ['version' => 1])->assertOk();
        $user = User::create([
            'name' => 'Observer',
            'email' => 'observer@example.test',
            'password' => 'test',
            'role' => 'platform_staff',
            'platform_role_id' => $id,
        ])->fresh();
        $this->assertTrue($user->hasPermission('platform.health.read'));
        $this->patchJson("/api/v1/admin/platform-roles/$id", [
            'name' => 'Observer',
            'permissions' => [],
            'version' => 1,
        ])->assertOk();
        $this->assertTrue($user->hasPermission('platform.health.read'));
        $this->postJson("/api/v1/admin/platform-roles/$id/activate", ['version' => 2])->assertConflict();
        $this->postJson("/api/v1/admin/platform-roles/$id/check", ['version' => 2])->assertOk();
        $this->postJson("/api/v1/admin/platform-roles/$id/activate", ['version' => 2])->assertOk();
        $this->assertFalse($user->hasPermission('platform.health.read'));
        $this->patchJson("/api/v1/admin/platform-roles/$id", [
            'name' => 'Observer',
            'permissions' => [],
            'version' => 1,
        ])->assertConflict();
    }
    public function test_system_role_is_locked_and_unknown_permissions_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $id = DB::table('identity_platform_roles')->where('locked', true)->value('id');
        $this->patchJson("/api/v1/admin/platform-roles/$id", [
            'name' => 'System Administrator',
            'permissions' => [],
            'version' => 1,
        ])->assertForbidden();
        $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Fake',
            'permissions' => ['*'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Fake',
            'permissions' => ['billing.unimplemented'],
        ])->assertUnprocessable();
    }
    public function test_support_can_read_audit_but_cannot_change_roles_or_select_tenants(): void
    {
        $role = DB::table('identity_platform_roles')->where('name', 'Support')->value('id');
        $support = User::create([
            'name' => 'Support',
            'email' => 'support@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'platform_staff',
            'platform_role_id' => $role,
        ])->fresh();
        $this->actingAs($support)->getJson('/api/v1/admin/audit-log')->assertOk();
        $this->getJson('/api/v1/support')->assertOk();
        $this->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonFragment([
                'installed_modules' => [
                    'billing',
                    'customer',
                    'identity',
                    'provisioning',
                    'reporting',
                    'reservation',
                    'support',
                    'weather',
                    'widget',
                ],
            ]);
        $this->getJson('/api/v1/admin/modules')->assertForbidden();
        $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Escalation',
            'permissions' => [],
        ])->assertForbidden();
        $this->postJson('/api/v1/admin/users', ['role' => 'system_admin'])->assertForbidden();
        $this->withHeader('X-Tenant-ID', '1')->getJson('/api/v1/restaurant/profile')->assertForbidden();
        $this->getJson('/api/v1/admin/database-servers')->assertForbidden();
    }
    public function test_assigned_and_locked_roles_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $id = DB::table('identity_platform_roles')->where('name', 'Support')->value('id');
        User::create([
            'name' => 'Assigned',
            'email' => 'assigned@example.test',
            'password' => 'test',
            'role' => 'platform_staff',
            'platform_role_id' => $id,
        ]);
        $this->deleteJson("/api/v1/admin/platform-roles/$id", ['version' => 1])->assertConflict();
        $locked = DB::table('identity_platform_roles')->where('locked', true)->value('id');
        $this->deleteJson("/api/v1/admin/platform-roles/$locked", ['version' => 1])->assertForbidden();
        $free = $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Unused',
            'permissions' => [],
        ])->json('id');
        $this->deleteJson("/api/v1/admin/platform-roles/$free", ['version' => 1])->assertNoContent();
    }
    public function test_platform_accounts_require_an_activated_assignable_role(): void
    {
        $this->actingAs($this->admin());
        $role = DB::table('identity_platform_roles')->where('name', 'Support')->value('id');
        $body = [
            'name' => 'Support',
            'email' => 'new@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'platform_staff',
            'platform_role_id' => $role,
        ];
        $this->postJson('/api/v1/admin/users', $body)->assertCreated();
        $this->postJson('/api/v1/admin/users', [
            ...$body,
            'email' => 'bad@example.test',
            'platform_role_id' => 999,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users', [
            ...$body,
            'email' => 'bad@example.test',
            'platform_role_id' => DB::table('identity_platform_roles')->where('locked', true)->value('id'),
        ])->assertUnprocessable();
    }
    public function test_restaurant_module_permissions_are_not_assignable_in_platform_roles(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Admin',
            'email' => 'root-scope@example.test',
            'password' => 'Test-password-only',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($user)
            ->postJson('/api/v1/admin/platform-roles', [
                'name' => 'Wrong scope',
                'permissions' => ['reporting.read'],
            ])
            ->assertUnprocessable();
    }
}
