<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\TenantDatabase;
use App\Modules\Billing\{RecurringBilling, StripeGateway, SendBillingMail};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Crypt, Queue, Mail};
use Tests\TestCase;
class BillingAutomationTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $owner;
    private int $subscription;
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(TenantDatabase::class, function ($m) {
            $m->shouldReceive('connect')->andReturnNull();
            $m->shouldReceive('disconnect')->andReturnNull();
        });
        $tenant = Tenant::create([
            'name' => 'Test',
            'email' => 'owner@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('b', 24),
            'database_user' => 'phu_' . str_repeat('b', 24),
            'database_password' => 'test-only',
        ]);
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Test-only-password',
            'role' => 'system_admin',
        ])->fresh();
        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'Test-only-password',
            'role' => 'restaurant_admin',
            'tenant_id' => $tenant->id,
        ])->fresh();
        $party = [
            'name' => 'Restaurant',
            'street' => 'Test 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'email' => 'invoice@example.test',
            'tax_id' => 'TEST',
            'tax_rate_bps' => 1900,
        ];
        DB::table('billing_settings')
            ->where('id', 1)
            ->update(['data' => json_encode($party)]);
        DB::table('billing_profiles')->insert(['tenant_id' => $tenant->id, 'data' => json_encode($party)]);
        DB::table('billing_products')
            ->where('module_code', 'reporting')
            ->update(['amount_cents' => 11900, 'available' => true]);
        DB::table('billing_automation')
            ->where('id', 1)
            ->update([
                'enabled' => true,
                'live' => true,
                'secrets' => Crypt::encryptString(
                    json_encode(['api_key' => 'sk_live_fixture', 'webhook_secret' => 'whsec_fixture']),
                ),
            ]);
        $this->subscription = DB::table('billing_subscriptions')->insertGetId([
            'reference' => 'c71ed27e-3486-4c77-b248-9848acb79291',
            'tenant_id' => $tenant->id,
            'module_code' => 'reporting',
            'amount_cents' => 11900,
            'state' => 'active',
            'provider_id' => 'sub_fixture',
            'customer_id' => 'cus_fixture',
            'live' => true,
            'consented_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    private function provider(string $status = 'paid', int $total = 11900): void
    {
        $sub = DB::table('billing_subscriptions')->find($this->subscription);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_fixture' => Http::response([
                'id' => 'sub_fixture',
                'livemode' => (bool) DB::table('billing_automation')->value('live'),
                'customer' => 'cus_fixture',
                'metadata' => ['platzhirsch_reference' => $sub->reference],
                'status' => 'active',
                'current_period_end' => now()->addMonth()->timestamp,
            ]),
            'https://api.stripe.com/v1/invoices/in_fixture' => Http::response([
                'id' => 'in_fixture',
                'livemode' => (bool) DB::table('billing_automation')->value('live'),
                'subscription' => 'sub_fixture',
                'customer' => 'cus_fixture',
                'currency' => 'eur',
                'total' => $total,
                'status' => $status,
                'amount_paid' => $status === 'paid' ? $total : 0,
                'amount_remaining' => $status === 'paid' ? 0 : $total,
                'due_date' => now()->subDay()->timestamp,
                'lines' => [
                    'has_more' => false,
                    'data' => [
                        [
                            'amount' => $total,
                            'quantity' => 1,
                            'proration' => false,
                            'period' => [
                                'start' => now()->subDays(8)->timestamp,
                                'end' => now()->addDays(22)->timestamp,
                            ],
                        ],
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/invoices?*' => Http::response(['data' => [], 'has_more' => false]),
        ]);
    }
    private function webhook(string $type = 'invoice.paid', ?string $signature = null)
    {
        $body = json_encode([
            'api_version' => '2024-06-20',
            'livemode' => (bool) DB::table('billing_automation')->value('live'),
            'type' => $type,
            'data' => ['object' => ['id' => 'in_fixture']],
        ]);
        $t = time();
        $signature ??= 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_fixture');
        return $this->call(
            'POST',
            '/api/v1/billing/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature],
            $body,
        );
    }
    public function test_duplicate_paid_events_create_one_invoice_and_one_entitlement(): void
    {
        $this->provider();
        $this->webhook()->assertOk();
        $this->webhook()->assertOk();
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertDatabaseHas('billing_invoices', [
            'payment_status' => 'paid',
            'total_cents' => 11900,
            'tax_cents' => 1900,
        ]);
        $this->assertDatabaseHas('billing_entitlements', ['status' => 'inactive']);
        $this->assertDatabaseHas('billing_settings', ['next_number' => 2]);
    }
    public function test_sandbox_never_consumes_real_numbers_or_grants_entitlements(): void
    {
        DB::table('billing_automation')->update(['live' => false]);
        DB::table('billing_subscriptions')->update(['live' => false]);
        $this->provider();
        $this->webhook()->assertOk();
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertDatabaseHas('billing_settings', ['next_number' => 1]);
        $this->assertDatabaseHas('billing_automation', ['next_test_number' => 2]);
        $this->assertStringStartsWith('TEST-PH-', DB::table('billing_invoices')->value('number'));
    }
    public function test_invalid_signature_and_amount_cannot_grant_access(): void
    {
        $this->provider('paid', 1);
        $this->webhook(signature: 't=1,v1=bad')->assertStatus(400);
        Http::assertNothingSent();
        $this->webhook()->assertStatus(409);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
    }
    public function test_failed_then_paid_preserves_invoice_and_stops_reminders(): void
    {
        $this->provider('open');
        $this->webhook('invoice.payment_failed')->assertOk();
        $i = DB::table('billing_invoices')->first();
        $payload = $i->payload;
        DB::table('billing_automation')->update(['send_reminders' => true]);
        app(RecurringBilling::class)->run();
        $this->assertDatabaseHas('billing_deliveries', [
            'invoice_id' => $i->id,
            'kind' => 'reminder',
            'level' => 1,
        ]);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->provider('paid');
        $this->webhook()->assertOk();
        $this->assertSame($payload, DB::table('billing_invoices')->first()->payload);
        $d = DB::table('billing_deliveries')->first();
        Mail::fake();
        app()->call([new SendBillingMail($d->id), 'handle']);
        $this->assertDatabaseHas('billing_deliveries', ['id' => $d->id, 'status' => 'skipped']);
        Mail::assertNothingSent();
    }
    public function test_settings_require_admin_password_and_keep_secrets_out_of_api(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/admin/billing/automation', [])->assertForbidden();
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/billing/automation')
            ->assertOk()
            ->assertJsonMissingPath('settings.secrets')
            ->assertJsonMissingPath('settings.api_key');
        config(['app.url' => 'https://platzhirsch.example']);
        $p = [
            'enabled' => true,
            'live' => true,
            'send_invoices' => true,
            'send_reminders' => true,
            'reminder_days' => 7,
            'revision' => 0,
            'password' => 'wrong',
            'confirmed' => true,
        ];
        $this->putJson('/api/v1/admin/billing/automation', $p)->assertForbidden();
        $this->putJson('/api/v1/admin/billing/automation', [
            ...$p,
            'password' => 'Test-only-password',
        ])->assertOk();
        $this->putJson('/api/v1/admin/billing/automation', [
            ...$p,
            'password' => 'Test-only-password',
        ])->assertConflict();
    }
    public function test_cross_tenant_subscription_actions_and_manual_payment_are_rejected(): void
    {
        $this->provider('open');
        $this->webhook()->assertOk();
        $order = DB::table('billing_orders')->first();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/billing/orders/' . $order->id . '/confirm', [
                'payment_reference' => 'manual',
                'payment_confirmed' => true,
            ])
            ->assertConflict();
        DB::table('billing_subscriptions')
            ->where('id', $this->subscription)
            ->update(['tenant_id' => 999]);
        $this->actingAs($this->owner)
            ->postJson(
                '/api/v1/restaurant/billing/automation/subscriptions/' . $this->subscription . '/cancel',
                ['confirmed' => true],
            )
            ->assertNotFound();
    }
    public function test_log_mailer_does_not_claim_delivery_success(): void
    {
        $this->provider();
        $this->webhook()->assertOk();
        $billing = app(RecurringBilling::class);
        $billing->enqueue(DB::table('billing_invoices')->first()->id);
        config(['mail.default' => 'log']);
        $d = DB::table('billing_deliveries')->first();
        app()->call([new SendBillingMail($d->id), 'handle']);
        $this->assertDatabaseHas('billing_deliveries', [
            'id' => $d->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }
}
