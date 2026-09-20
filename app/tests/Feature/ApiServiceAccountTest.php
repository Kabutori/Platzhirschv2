<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
class ApiServiceAccountTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private array $proof = ['password' => 'Test-password-123', 'confirmed' => true];
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'service-owner@example.test',
            'role' => 'system_admin',
            'password' => 'Test-password-123',
        ])->fresh();
        $this->actingAs($this->owner);
    }
    private function url(string $path): string
    {
        return 'https://localhost/api/' . $path;
    }
    private function account(): string
    {
        return $this->postJson($this->url('v1/access/service-accounts'), [
            ...$this->proof,
            'name' => 'Audit-Bot',
            'operations' => ['audit.get.admin_audit_log'],
        ])
            ->assertOk()
            ->json('id');
    }
    private function token(?string $account = null, array $extra = []): array
    {
        return $this->postJson($this->url('v1/access/tokens'), [
            ...$this->proof,
            'name' => 'Integration',
            'audience' => 'api',
            'scopes' => ['audit:read', 'support:read'],
            'days' => 30,
            'cidrs' => [],
            'service_account_id' => $account,
            ...$extra,
        ])
            ->assertOk()
            ->json();
    }
    public function test_service_identity_is_action_scoped_and_owner_or_service_disable_is_immediate(): void
    {
        $id = $this->account();
        $token = $this->token($id);
        $this->withToken($token['token']);
        $this->getJson($this->url('external/v1/audit/admin/audit-log'))->assertOk();
        $this->getJson($this->url('external/v1/support/support'))->assertForbidden();
        $this->assertDatabaseHas('api_access_events', ['service_account_id' => $id, 'http_status' => 403]);
        $this->putJson($this->url('v1/access/service-accounts/' . $id), [
            ...$this->proof,
            'name' => 'Audit-Bot',
            'operations' => [],
            'active' => false,
            'revision' => 0,
        ])->assertOk();
        $this->getJson($this->url('external/v1/catalog'))->assertUnauthorized();
        $this->putJson($this->url('v1/access/service-accounts/' . $id), [
            ...$this->proof,
            'name' => 'Audit-Bot',
            'operations' => ['audit.get.admin_audit_log'],
            'active' => true,
            'revision' => 0,
        ])->assertConflict();
        $this->putJson($this->url('v1/access/service-accounts/' . $id), [
            ...$this->proof,
            'name' => 'Audit-Bot',
            'operations' => ['audit.get.admin_audit_log'],
            'active' => true,
            'revision' => 1,
        ])->assertOk();
        DB::table('users')
            ->where('id', $this->owner->id)
            ->update(['active' => false]);
        $this->getJson($this->url('external/v1/catalog'))->assertUnauthorized();
    }
    public function test_rotation_preserves_restrictions_limits_overlap_and_cannot_repeat(): void
    {
        $old = $this->token(null, [
            'operations' => ['audit.get.admin_audit_log'],
            'cidrs' => ['127.0.0.1/32'],
        ]);
        $path = $this->url('v1/access/tokens/' . $old['id'] . '/rotate');
        $this->postJson($path, [...$this->proof, 'days' => 30, 'overlap_hours' => 25])->assertUnprocessable();
        $new = $this->postJson($path, [...$this->proof, 'days' => 30, 'overlap_hours' => 1])
            ->assertOk()
            ->json();
        $this->postJson($path, [...$this->proof, 'days' => 30, 'overlap_hours' => 1])->assertConflict();
        $this->assertSame(
            DB::table('api_tokens')->find($old['id'])->operations,
            DB::table('api_tokens')->find($new['id'])->operations,
        );
        $this->assertSame('["127.0.0.1\/32"]', DB::table('api_tokens')->find($new['id'])->cidrs);
        $this->withToken($old['token'])->getJson($this->url('external/v1/audit/admin/audit-log'))->assertOk();
        $this->travel(61)->minutes();
        $this->withToken($old['token'])->getJson($this->url('external/v1/catalog'))->assertUnauthorized();
        $this->withToken($new['token'])->getJson($this->url('external/v1/audit/admin/audit-log'))->assertOk();
        $this->getJson($this->url('external/v1/support/support'))->assertForbidden();
    }
    public function test_other_owner_cannot_manage_service_or_rotate_its_token(): void
    {
        $id = $this->account();
        $token = $this->token($id);
        $other = User::create([
            'name' => 'Other',
            'email' => 'other-service@example.test',
            'role' => 'system_admin',
            'password' => 'Test-password-123',
        ])->fresh();
        $this->actingAs($other);
        $this->postJson($this->url('v1/access/tokens/' . $token['id'] . '/rotate'), [
            ...$this->proof,
            'days' => 30,
            'overlap_hours' => 0,
        ])->assertNotFound();
        $this->putJson($this->url('v1/access/service-accounts/' . $id), [
            ...$this->proof,
            'name' => 'stolen',
            'operations' => [],
            'active' => false,
            'revision' => 0,
        ])->assertNotFound();
        $this->postJson($this->url('v1/access/tokens'), [
            ...$this->proof,
            'name' => 'stolen',
            'audience' => 'api',
            'scopes' => ['audit:read'],
            'days' => 30,
            'cidrs' => [],
            'service_account_id' => $id,
        ])->assertNotFound();
    }
}
