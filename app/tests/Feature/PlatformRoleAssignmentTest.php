<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class PlatformRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;
    public function test_assignment_requires_active_role_and_detects_stale_updates(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'system_admin',
            'password' => 'test',
        ])->fresh();
        $support = DB::table('identity_platform_roles')->where('name', 'Support')->value('id');
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'role' => 'platform_staff',
            'password' => 'test',
            'platform_role_id' => $support,
        ])->fresh();
        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/admin/platform-roles', [
            'name' => 'Auditor',
            'permissions' => ['platform.audit.read'],
        ])
            ->assertOk()
            ->json('id');
        $body = ['platform_role_id' => $id, 'expected_platform_role_id' => $support];
        $this->patchJson('/api/v1/admin/users/' . $staff->id, $body)->assertUnprocessable();
        $this->postJson('/api/v1/admin/platform-roles/' . $id . '/check', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/admin/platform-roles/' . $id . '/activate', ['version' => 1])->assertOk();
        $this->patchJson('/api/v1/admin/users/' . $staff->id, $body)
            ->assertOk()
            ->assertJsonPath('platform_role_id', $id);
        $this->patchJson('/api/v1/admin/users/' . $staff->id, $body)->assertConflict();
        $this->assertFalse($staff->fresh()->hasPermission('support.access'));
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'user.platform_role_changed',
            'resource' => (string) $staff->id,
        ]);
        $this->actingAs($staff->fresh())
            ->patchJson('/api/v1/admin/users/' . $staff->id, [
                'platform_role_id' => $support,
                'expected_platform_role_id' => $id,
            ])
            ->assertForbidden();
    }
}
