<?php
namespace Tests\Feature;

use App\Contracts\Module\ProvisioningDispatcher;
use App\Registration\{VerificationMail, WebsiteClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.enabled' => true, 'mail.default' => 'smtp', 'app.url' => 'https://platzhirsch.example',
            'registration.privacy_url' => 'https://platzhirsch.example/datenschutz',
            'registration.imprint_url' => 'https://platzhirsch.example/impressum']);
        Mail::fake();
    }
    private function data(): array
    {
        return ['business_name' => 'Zur Linde', 'owner_name' => 'Anna Beispiel', 'website' => 'www.linde.de',
            'email' => 'kontakt@linde.de', 'privacy' => '1', 'fax_number' => ''];
    }
    private function website(): void
    {
        $this->mock(WebsiteClient::class)->shouldReceive('html')->with('https://www.linde.de/')
            ->andReturn('<html><h1>Restaurant Zur Linde</h1><p>Unsere Speisekarte</p></html>');
    }
    private function token(): string
    {
        $token = '';
        Mail::assertSent(VerificationMail::class, function ($mail) use (&$token) {
            $this->assertStringStartsWith('https://platzhirsch.example/registrierung/bestaetigen/', $mail->confirmationUrl);
            $token = basename($mail->confirmationUrl);
            return true;
        });
        return $token;
    }
    public function test_registration_requires_website_check_and_email_confirmation_before_provisioning(): void
    {
        $this->website();
        $this->mock(ProvisioningDispatcher::class)->shouldReceive('create')->once()->with(\Mockery::type('int'));
        $this->get('/')->assertOk()->assertSee('Website prüfen & registrieren', false);
        $this->post('/registrierung', $this->data())->assertRedirect('/registrierung');
        $token = $this->token();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(hash('sha256', $token), DB::table('registration_requests')->value('token_hash'));
        $this->get('/registrierung/bestaetigen/' . $token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('tenants', 0); // Mail scanners must not consume links.
        $this->post('/registrierung/bestaetigen/' . $token, [
            'password' => 'Strong-test-password-2026', 'password_confirmation' => 'Strong-test-password-2026',
        ])->assertRedirect('/registrierung');
        $this->assertDatabaseHas('tenants', ['name' => 'Zur Linde', 'status' => 'provisioning']);
        $this->assertDatabaseHas('users', ['email' => 'kontakt@linde.de', 'role' => 'restaurant_admin']);
        $this->assertNull(DB::table('registration_requests')->value('token_hash'));
        $this->post('/registrierung/bestaetigen/' . $token, [
            'password' => 'Strong-test-password-2026', 'password_confirmation' => 'Strong-test-password-2026',
        ])->assertStatus(410);
        $this->assertDatabaseCount('tenants', 1);
    }
    public function test_domain_mismatch_and_honeypot_never_fetch_or_send_mail(): void
    {
        $this->mock(WebsiteClient::class)->shouldNotReceive('html');
        $this->post('/registrierung', [...$this->data(), 'email' => 'person@gmail.com'])->assertSessionHasErrors('email');
        $this->post('/registrierung', [...$this->data(), 'fax_number' => 'spam'])->assertSessionHasErrors('fax_number');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('registration_requests', 0);
    }
    public function test_expired_links_cannot_create_accounts_and_are_pruned(): void
    {
        $this->website();
        $this->mock(ProvisioningDispatcher::class)->shouldNotReceive('create');
        $this->post('/registrierung', $this->data());
        $token = $this->token();
        $this->travel(25)->hours();
        $this->get('/registrierung/bestaetigen/' . $token)->assertStatus(410);
        $this->artisan('registration:prune')->assertSuccessful();
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('tenants', 0);
    }
    public function test_closed_registration_and_unconfigured_mail_are_not_simulated_as_success(): void
    {
        config(['mail.default' => 'log']);
        $this->get('/')->assertOk()->assertSee('Online-Registrierung wird vorbereitet');
        $this->post('/registrierung', $this->data())->assertStatus(503);
        Mail::assertNothingSent();
    }
    public function test_duplicate_submission_sends_only_one_link(): void
    {
        $this->website();
        $this->post('/registrierung', $this->data())->assertRedirect('/registrierung');
        $this->post('/registrierung', $this->data())->assertRedirect('/registrierung');
        Mail::assertSent(VerificationMail::class, 1);
        $this->assertDatabaseCount('registration_requests', 1);
        $this->assertDatabaseCount('tenants', 0);
    }
    public function test_submit_is_rate_limited_before_more_website_requests(): void
    {
        $this->website();
        $this->post('/registrierung', $this->data());
        $this->post('/registrierung', $this->data());
        $this->post('/registrierung', $this->data())->assertStatus(429);
    }
}
