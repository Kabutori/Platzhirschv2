<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\TenantDatabase;
use App\Jobs\ActivateTenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Tests\TestCase;
class InvoiceTest extends TestCase
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
    private function prepareInvoice(): int
    {
        $party = [
            'name' => 'Test <script>alert(1)</script>',
            'street' => 'Teststraße 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'email' => 'billing@example.test',
            'tax_id' => 'DE-test',
            'revision' => 0,
        ];
        $this->actingAs($this->admin)
            ->putJson('/api/v1/admin/billing/settings', [...$party, 'tax_rate_bps' => 1900])
            ->assertOk();
        $this->putJson('/api/v1/admin/billing/profiles/' . $this->tenant->id, $party)->assertOk();
        return DB::table('billing_orders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'module_code' => 'reporting',
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'amount_cents' => 11900,
            'currency' => 'EUR',
            'status' => 'paid',
            'payment_reference' => 'bank-1',
            'period_start' => '2026-09-01 00:00:00',
            'period_end' => '2026-10-01 00:00:00',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    public function test_invoice_snapshot_numbering_and_cancellation_are_idempotent(): void
    {
        $order = $this->prepareInvoice();
        $id = $this->postJson('/api/v1/admin/billing/orders/' . $order . '/invoice')
            ->assertCreated()
            ->json('id');
        $this->postJson('/api/v1/admin/billing/orders/' . $order . '/invoice')
            ->assertOk()
            ->assertJsonPath('id', $id);
        $this->assertDatabaseHas('billing_invoices', [
            'id' => $id,
            'total_cents' => 11900,
            'tax_cents' => 1900,
        ]);
        $payload = DB::table('billing_invoices')->where('id', $id)->value('payload');
        DB::table('billing_profiles')->update(['data' => '{}']);
        $this->postJson('/api/v1/admin/billing/documents/' . $id . '/issue')->assertUnprocessable();
        $number = $this->postJson('/api/v1/admin/billing/documents/' . $id . '/issue', ['confirmed' => true])
            ->assertOk()
            ->json('number');
        $this->postJson('/api/v1/admin/billing/documents/' . $id . '/issue', ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('number', $number);
        $this->deleteJson('/api/v1/admin/billing/documents/' . $id)->assertConflict();
        $this->get('/api/v1/admin/billing/documents/' . $id . '/print')
            ->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>', false);
        $credit = $this->postJson('/api/v1/admin/billing/documents/' . $id . '/cancel', [
            'confirmed' => true,
            'reason' => 'Korrektur',
        ])
            ->assertOk()
            ->json('id');
        $this->postJson('/api/v1/admin/billing/documents/' . $id . '/cancel', [
            'confirmed' => true,
            'reason' => 'Korrektur',
        ])
            ->assertOk()
            ->assertJsonPath('id', $credit);
        $this->assertDatabaseHas('billing_invoices', [
            'id' => $credit,
            'total_cents' => -11900,
            'tax_cents' => -1900,
        ]);
        $this->assertSame($payload, DB::table('billing_invoices')->where('id', $id)->value('payload'));
        $this->assertDatabaseHas('billing_settings', ['next_number' => 3]);
    }
    public function test_tenant_cannot_see_drafts_or_foreign_invoices_or_admin_actions(): void
    {
        $order = $this->prepareInvoice();
        $id = $this->postJson('/api/v1/admin/billing/orders/' . $order . '/invoice')->json('id');
        $this->actingAs($this->owner)
            ->getJson('/api/v1/restaurant/billing')
            ->assertOk()
            ->assertJsonCount(0, 'invoices.data');
        $this->get('/api/v1/restaurant/billing/documents/' . $id . '/print')->assertNotFound();
        $this->postJson('/api/v1/admin/billing/documents/' . $id . '/issue', [
            'confirmed' => true,
        ])->assertForbidden();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/billing/documents/' . $id . '/issue', ['confirmed' => true])
            ->assertOk();
        $this->actingAs($this->owner)
            ->get('/api/v1/restaurant/billing/documents/' . $id . '/print')
            ->assertOk();
        DB::table('billing_invoices')
            ->where('id', $id)
            ->update(['tenant_id' => $this->tenant->id + 1]);
        $this->withHeader('X-Tenant-ID', (string) ($this->tenant->id + 1))
            ->get('/api/v1/restaurant/billing/documents/' . $id . '/print')
            ->assertNotFound();
    }
    public function test_pending_orders_and_stale_settings_are_rejected(): void
    {
        $order = $this->prepareInvoice();
        DB::table('billing_orders')
            ->where('id', $order)
            ->update(['status' => 'pending']);
        $this->postJson('/api/v1/admin/billing/orders/' . $order . '/invoice')->assertUnprocessable();
        $s = $this->getJson('/api/v1/admin/billing/documents')->json('settings');
        $this->putJson('/api/v1/admin/billing/settings', [...$s, 'revision' => 0])->assertConflict();
        $this->putJson('/api/v1/admin/billing/settings', [
            ...$s,
            'tax_rate_bps' => 0,
            'tax_note' => '',
        ])->assertUnprocessable();
    }
}
