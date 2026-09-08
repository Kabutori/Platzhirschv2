<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\TenantDatabase;
use App\Jobs\ActivateTenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Tests\TestCase;
class ModuleLifecycleTest extends TestCase
{
    use RefreshDatabase;
    private Tenant $tenant;
    private User $owner;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(TenantDatabase::class, function ($m) {
            $m->shouldReceive('connect')->andReturnNull();
            $m->shouldReceive('disconnect')->andReturnNull();
        });
        $this->tenant = Tenant::create([
            'name' => 'Lifecycle',
            'email' => 'tenant@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'test-only',
        ])->fresh();
        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'Test-only-password',
            'role' => 'restaurant_admin',
            'tenant_id' => $this->tenant->id,
        ])->fresh();
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Test-only-password',
            'role' => 'system_admin',
        ])->fresh();
        Queue::fake();
    }
    private function order(): int
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/billing/products/reporting', [
                'amount_cents' => 1900,
                'available' => true,
            ])
            ->assertOk();
        return $this->actingAs($this->owner)
            ->postJson('/api/v1/restaurant/modules/orders', [
                'module_code' => 'reporting',
                'expected_amount_cents' => 1900,
                'request_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertCreated()
            ->json('id');
    }
    public function test_default_product_is_not_sellable_and_staff_cannot_order(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/restaurant/modules/orders', [
                'module_code' => 'reporting',
                'expected_amount_cents' => 1900,
                'request_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertUnprocessable();
        $this->owner->update(['role' => 'staff']);
        $this->getJson('/api/v1/restaurant/modules')->assertForbidden();
        $this->getJson('/api/v1/admin/billing')->assertForbidden();
    }
    public function test_purchase_retries_keep_price_and_cannot_activate_before_confirmation(): void
    {
        $id = $this->order();
        $order = DB::table('billing_orders')->find($id);
        $this->postJson('/api/v1/restaurant/modules/orders', [
            'module_code' => 'reporting',
            'expected_amount_cents' => 1900,
            'request_key' => $order->request_key,
        ])
            ->assertOk()
            ->assertJsonPath('id', $id);
        $this->postJson('/api/v1/restaurant/modules/reporting/activation', [
            'enabled' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('billing_orders', 1);
        Queue::assertNothingPushed();
        $this->assertSame(1900, $order->amount_cents);
    }
    public function test_confirmed_payment_enables_migration_once_and_confirmation_retry_does_not_extend(): void
    {
        $id = $this->order();
        $body = ['payment_reference' => 'test-payment-1', 'payment_confirmed' => true];
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/billing/orders/' . $id . '/confirm', $body)
            ->assertOk();
        $until = DB::table('billing_entitlements')->value('paid_until');
        $this->postJson('/api/v1/admin/billing/orders/' . $id . '/confirm', $body)->assertOk();
        $this->assertSame($until, DB::table('billing_entitlements')->value('paid_until'));
        $this->actingAs($this->owner)
            ->postJson('/api/v1/restaurant/modules/reporting/activation', ['enabled' => true])
            ->assertAccepted();
        $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'status' => 'upgrading']);
        $this->assertDatabaseHas('billing_entitlements', [
            'tenant_id' => $this->tenant->id,
            'status' => 'activating',
        ]);
        Queue::assertPushed(ActivateTenantModule::class, 1);
        $this->postJson('/api/v1/restaurant/modules/reporting/activation', [
            'enabled' => true,
        ])->assertForbidden();
        Queue::assertPushed(ActivateTenantModule::class, 1);
    }
    public function test_payment_confirmation_requires_attestation_and_admin(): void
    {
        $id = $this->order();
        $this->postJson('/api/v1/admin/billing/orders/' . $id . '/confirm', [
            'payment_reference' => 'test',
            'payment_confirmed' => true,
        ])->assertForbidden();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/billing/orders/' . $id . '/confirm', ['payment_reference' => 'test'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('billing_entitlements', 0);
    }
    public function test_expired_or_foreign_entitlement_does_not_grant_access(): void
    {
        DB::table('billing_entitlements')->insert([
            'tenant_id' => $this->tenant->id,
            'module_code' => 'reporting',
            'status' => 'active',
            'paid_until' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->owner)
            ->getJson('/api/v1/restaurant/reporting?from=2026-01-01&to=2026-01-01')
            ->assertForbidden();
        $this->postJson('/api/v1/restaurant/modules/reporting/activation', [
            'enabled' => true,
        ])->assertUnprocessable();
        DB::table('billing_entitlements')->update([
            'tenant_id' => $this->tenant->id + 1,
            'paid_until' => now()->addMonth(),
        ]);
        $this->withHeader('X-Tenant-ID', (string) ($this->tenant->id + 1))
            ->getJson('/api/v1/restaurant/modules')
            ->assertOk()
            ->assertJsonCount(0, 'entitlements');
    }
}
