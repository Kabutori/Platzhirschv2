<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class OrganizationTest extends TestCase
{
    use RefreshDatabase;
    public function test_hierarchy_rejects_cycles_stale_edits_and_deletion_of_parents(): void
    {
        $u = User::create([
            'name' => 'Admin',
            'email' => 'org@example.test',
            'password' => 'test',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($u);
        $root = $this->postJson('/api/v1/admin/organizations', ['name' => 'Gruppe'])
            ->assertOk()
            ->json('id');
        $child = $this->postJson('/api/v1/admin/organizations', ['name' => 'Region', 'parent_id' => $root])
            ->assertOk()
            ->json('id');
        $this->patchJson('/api/v1/admin/organizations/' . $root, [
            'name' => 'Gruppe',
            'parent_id' => $child,
            'version' => 1,
        ])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/organizations/' . $root)->assertConflict();
        $this->patchJson('/api/v1/admin/organizations/' . $child, [
            'name' => 'Neue Region',
            'parent_id' => $root,
            'version' => 1,
        ])->assertOk();
        $this->patchJson('/api/v1/admin/organizations/' . $child, [
            'name' => 'Alt',
            'parent_id' => $root,
            'version' => 1,
        ])->assertConflict();
        $this->deleteJson('/api/v1/admin/organizations/' . $child)->assertNoContent();
        $this->deleteJson('/api/v1/admin/organizations/' . $root)->assertNoContent();
        $u->update(['role' => 'restaurant_admin']);
        $this->actingAs($u->fresh());
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
    }
}
