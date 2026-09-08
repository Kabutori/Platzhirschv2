<?php
namespace Tests\Feature;
use App\Models\User;
use App\Jobs\ProvisionTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Hash};
use Tests\TestCase;
class DemoRestaurantTest extends TestCase
{
    use RefreshDatabase;
    public function test_demo_creates_an_isolated_restaurant_and_hashed_owner_login(): void
    {
        Queue::fake();
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'system_admin',
        ])->fresh();
        $body = [
            'name' => 'Testrestaurant',
            'owner_name' => 'Test Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-test-password-2026',
            'password_confirmation' => 'Strong-test-password-2026',
        ];
        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/test-restaurant', $body)
            ->assertAccepted()
            ->assertJsonPath('tenant.is_demo', true)
            ->assertJsonMissingPath('password');
        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($body['password'], $owner->password));
        $this->assertSame('restaurant_admin', $owner->role);
        $this->assertSame($response->json('tenant.id'), $owner->tenant_id);
        Queue::assertPushed(ProvisionTenant::class, fn($job) => $job->tenantId === $owner->tenant_id);
        $this->postJson('/api/v1/admin/test-restaurant', $body)->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
    }
}
