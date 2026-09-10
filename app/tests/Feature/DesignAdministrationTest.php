<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DesignAdministrationTest extends TestCase
{
    use RefreshDatabase;
    private function account(string $role, string $email): User
    {
        return User::create([
            'name' => 'Tester',
            'email' => $email,
            'password' => 'long-test-password-2026',
            'role' => $role,
        ])->fresh();
    }
    public function test_release_drafts_are_hidden_and_updates_are_version_checked(): void
    {
        $admin = $this->account('system_admin', 'admin@design.test');
        $reader = $this->account('staff', 'reader@design.test');
        $data = [
            'module' => 'Reservation',
            'version' => '0.2.0',
            'category' => 'feature',
            'notes' => 'Neue Ansicht',
            'publish' => false,
        ];
        $id = $this->actingAs($admin)->postJson('/api/v1/releases', $data)->assertOk()->json('id');
        $this->actingAs($reader)->getJson('/api/v1/releases')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/releases', $data)->assertForbidden();
        $this->actingAs($admin)
            ->patchJson('/api/v1/releases/' . $id, [...$data, 'publish' => true, 'revision' => 1])
            ->assertOk();
        $this->patchJson('/api/v1/releases/' . $id, [...$data, 'revision' => 1])->assertConflict();
        $this->actingAs($reader)->getJson('/api/v1/releases')->assertJsonPath('data.0.notes', 'Neue Ansicht');
        $this->actingAs($admin)
            ->postJson('/api/v1/releases', [...$data, 'git_url' => 'javascript:alert(1)'])
            ->assertUnprocessable();
        $reader->update(['active' => false]);
        $this->actingAs($reader->fresh())->getJson('/api/v1/releases')->assertForbidden();
    }
    public function test_audit_scopes_filters_and_pagination_do_not_mix_entries(): void
    {
        DB::table('audit_entries')->insert([
            ['action' => 'platform.changed', 'tenant_id' => null, 'created_at' => now()],
            ['action' => 'reservation.created', 'tenant_id' => 42, 'created_at' => now()],
            ['action' => 'reservation.cancelled', 'tenant_id' => 43, 'created_at' => now()],
        ]);
        $this->actingAs($this->account('system_admin', 'audit@design.test'));
        $this->getJson('/api/v1/admin/audit-log?scope=platform')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'platform.changed');
        $this->getJson('/api/v1/admin/audit-log?scope=tenant&tenant_id=42&search=created')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', 42);
        $this->getJson('/api/v1/admin/audit-log?scope=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/health')
            ->assertOk()
            ->assertJsonStructure(['migrations']);
    }
}
