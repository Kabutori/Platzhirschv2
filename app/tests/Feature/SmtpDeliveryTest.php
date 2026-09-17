<?php
namespace Tests\Feature;
use App\Models\User;
use App\Registration\WebsiteClient;
use App\Contracts\Module\ProvisioningDispatcher;
use App\Modules\Billing\{RecurringBilling, SendBillingMail};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class SmtpDeliveryTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = getenv('PLATZHIRSCH_SMTP_TEST_DIRECTORY') ?: '';
        if (!$this->directory) {
            $this->markTestSkipped('Local STARTTLS sink is provided by the dedicated CI step.');
        }
        foreach (glob($this->directory . '/*.eml') as $f) {
            unlink($f);
        }
        @unlink($this->directory . '/reject');
        config(['app.url' => 'https://platzhirsch.example']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'Test-password-123',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($admin)
            ->putJson('/api/v1/admin/mail-settings', [
                'enabled' => true,
                'host' => '127.0.0.1',
                'port' => 25252,
                'security' => 'starttls',
                'username' => 'test',
                'password' => 'test',
                'from_address' => 'mail@example.test',
                'from_name' => 'Platzhirsch',
            ])
            ->assertOk();
    }
    public function test_saved_smtp_settings_send_real_tls_mail_and_registration_link_creates_account_once(): void
    {
        $this->postJson('/api/v1/admin/mail-settings/test')->assertOk();
        $this->postJson('/api/v1/admin/mail-settings/send-test', ['confirmed' => true])->assertOk();
        $this->assertCount(1, glob($this->directory . '/*.eml'));
        $this->putJson('/api/v1/admin/registration-settings', [
            'enabled' => true,
            'privacy_url' => 'https://platzhirsch.example/privacy',
            'imprint_url' => 'https://platzhirsch.example/imprint',
            'revision' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('available', true);
        $this->mock(WebsiteClient::class)
            ->shouldReceive('html')
            ->with('https://www.linde.de/')
            ->andReturn('<h1>Restaurant</h1><p>Speisekarte</p>');
        $this->mock(ProvisioningDispatcher::class)->shouldReceive('create')->once();
        $this->post('/registrierung', [
            'business_name' => 'Linde',
            'owner_name' => 'Anna',
            'website' => 'www.linde.de',
            'email' => 'kontakt@linde.de',
            'privacy' => '1',
            'fax_number' => '',
        ])->assertRedirect('/registrierung');
        $files = glob($this->directory . '/*.eml');
        $this->assertCount(2, $files);
        $token = '';
        foreach ($files as $f) {
            $body = quoted_printable_decode(file_get_contents($f));
            if (preg_match('~/registrierung/bestaetigen/([a-f0-9]{64})~', $body, $match)) {
                $token = $match[1];
            }
        }
        $this->assertNotSame('', $token);
        $this->assertDatabaseCount('tenants', 0);
        $this->get('/registrierung/bestaetigen/' . $token)->assertOk();
        $this->assertDatabaseCount('tenants', 0);
        $this->post('/registrierung/bestaetigen/' . $token, [
            'password' => 'Strong-test-password-2026',
            'password_confirmation' => 'Strong-test-password-2026',
        ])->assertRedirect('/registrierung');
        $this->assertDatabaseHas('users', ['email' => 'kontakt@linde.de', 'role' => 'restaurant_admin']);
        $this->assertDatabaseCount('tenants', 1);
        $this->post('/registrierung/bestaetigen/' . $token, [
            'password' => 'Strong-test-password-2026',
            'password_confirmation' => 'Strong-test-password-2026',
        ])->assertStatus(410);
    }
    public function test_smtp_rejection_is_visible_and_registration_does_not_leave_unusable_request(): void
    {
        file_put_contents($this->directory . '/reject', '1');
        $this->postJson('/api/v1/admin/mail-settings/send-test', [
            'confirmed' => true,
        ])->assertUnprocessable();
        $this->putJson('/api/v1/admin/registration-settings', [
            'enabled' => true,
            'privacy_url' => 'https://platzhirsch.example/privacy',
            'imprint_url' => 'https://platzhirsch.example/imprint',
            'revision' => 0,
        ])->assertOk();
        $this->mock(WebsiteClient::class)
            ->shouldReceive('html')
            ->andReturn('<h1>Restaurant</h1><p>Speisekarte</p>');
        $this->post('/registrierung', [
            'business_name' => 'Linde',
            'owner_name' => 'Anna',
            'website' => 'www.linde.de',
            'email' => 'kontakt@linde.de',
            'privacy' => '1',
            'fax_number' => '',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('tenants', 0);
        @unlink($this->directory . '/reject');
    }
    public function test_invoice_and_reminder_use_saved_smtp_and_preserve_delivery_idempotency(): void
    {
        $party = [
            'name' => 'Test',
            'street' => 'Test 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'email' => 'invoice@example.test',
            'tax_id' => 'TEST',
        ];
        $id = DB::table('billing_invoices')->insertGetId([
            'tenant_id' => 1,
            'kind' => 'invoice',
            'status' => 'issued',
            'number' => 'PH-2026-000001',
            'total_cents' => 11900,
            'tax_cents' => 1900,
            'issued_at' => now(),
            'payment_status' => 'unpaid',
            'due_at' => now()->subDay(),
            'payload' => json_encode([
                'seller' => $party,
                'buyer' => $party,
                'service_start' => '2026-09-01',
                'service_end' => '2026-10-01',
                'description' => 'Monatsabo',
                'net_cents' => 10000,
                'tax_cents' => 1900,
                'gross_cents' => 11900,
                'tax_rate_bps' => 1900,
                'payment_reference' => 'fixture',
            ]),
        ]);
        $billing = app(RecurringBilling::class);
        $billing->enqueue($id);
        $delivery = DB::table('billing_deliveries')->first();
        app()->call([new SendBillingMail($delivery->id), 'handle']);
        app()->call([new SendBillingMail($delivery->id), 'handle']);
        $this->assertCount(1, glob($this->directory . '/*.eml'));
        $this->assertStringContainsString(
            'application/pdf',
            file_get_contents(glob($this->directory . '/*.eml')[0]),
        );
        $this->assertDatabaseHas('billing_deliveries', [
            'id' => $delivery->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        $billing->enqueue($id, 'reminder', 1);
        $reminder = DB::table('billing_deliveries')->where('kind', 'reminder')->first();
        app()->call([new SendBillingMail($reminder->id), 'handle']);
        $this->assertCount(2, glob($this->directory . '/*.eml'));
        $this->assertDatabaseHas('billing_deliveries', ['id' => $reminder->id, 'status' => 'sent']);
    }
}
