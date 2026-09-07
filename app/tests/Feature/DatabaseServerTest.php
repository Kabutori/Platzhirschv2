<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Provisioning\Application\ServerProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DatabaseServerTest extends TestCase
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
    private function payload(): array
    {
        return [
            'name' => 'Test database',
            'host' => 'db.example.test',
            'port' => 3306,
            'region' => 'EU',
            'purpose' => 'test',
            'database' => 'ph_test',
            'username' => 'ph_probe',
            'password' => 'secret-for-test-only',
            'tls_required' => true,
        ];
    }
    public function test_server_secrets_are_encrypted_and_never_returned(): void
    {
        $this->actingAs($this->admin());
        $result = $this->postJson('/api/v1/admin/database-servers', $this->payload())
            ->assertOk()
            ->assertJsonMissingPath('password');
        $id = $result->json('id');
        $stored = DB::table('prov_db_servers')->find($id)->password;
        $this->assertNotSame('secret-for-test-only', $stored);
        $this->getJson('/api/v1/admin/database-servers')
            ->assertOk()
            ->assertJsonMissingPath('servers.0.password');
        $payload = [...$this->payload(), 'password' => '', 'version' => 1, 'name' => 'Changed'];
        $this->patchJson('/api/v1/admin/database-servers/' . $id, $payload)
            ->assertOk()
            ->assertJsonPath('version', 2);
        $this->assertSame($stored, DB::table('prov_db_servers')->find($id)->password);
        $this->patchJson('/api/v1/admin/database-servers/' . $id, $payload)->assertConflict();
        $this->assertDatabaseHas('audit_entries', ['action' => 'provisioning.server_saved']);
    }
    public function test_registry_and_permission_catalog_are_read_only(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/modules')
            ->assertOk()
            ->assertJsonPath('0.code', 'provisioning');
        $this->getJson('/api/v1/admin/permissions/families')
            ->assertOk()
            ->assertJsonPath('0.module', 'provisioning');
        $this->postJson('/api/v1/admin/modules', ['code' => 'untrusted'])->assertStatus(405);
    }
    public function test_restaurant_accounts_and_guests_cannot_manage_servers(): void
    {
        $this->getJson('/api/v1/admin/database-servers')->assertUnauthorized();
        $user = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => 'test',
            'role' => 'staff',
        ])->fresh();
        $this->actingAs($user)->getJson('/api/v1/admin/database-servers')->assertForbidden();
        $this->postJson('/api/v1/admin/database-servers', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/admin/database-servers/1/test', [
            'check' => 'connection',
        ])->assertForbidden();
    }
    public function test_probe_receives_decrypted_credentials_but_response_has_no_secrets(): void
    {
        $this->actingAs($this->admin());
        $id = $this->postJson('/api/v1/admin/database-servers', $this->payload())->json('id');
        $probe = $this->createMock(ServerProbe::class);
        $probe
            ->expects($this->once())
            ->method('run')
            ->with($this->callback(fn($s) => $s['password'] === 'secret-for-test-only'), false)
            ->willReturn(['ok' => false, 'code' => 'authentication_failed']);
        $this->app->instance(ServerProbe::class, $probe);
        $this->postJson('/api/v1/admin/database-servers/' . $id . '/test', ['check' => 'connection'])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'code' => 'authentication_failed']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'provisioning.server_test.connection.failed']);
    }
    public function test_dsn_injection_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/database-servers', [
                ...$this->payload(),
                'host' => 'localhost;dbname=other',
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('prov_db_servers', 0);
    }
}
