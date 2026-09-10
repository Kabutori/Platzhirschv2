<?php
namespace Tests\Feature;
use App\Models\User;
use App\MailSettings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;
    private function payload(): array { return ['enabled'=>true,'host'=>'smtp.example.test','port'=>587,'security'=>'starttls','username'=>'mailer','password'=>'secret-only-in-test','from_address'=>'mail@example.test','from_name'=>'Platzhirsch']; }
    private function user(string $role): User { return User::create(['name'=>'Test','email'=>$role.'@example.test','password'=>'Test-password-123','role'=>$role])->fresh(); }
    public function test_only_root_admin_can_manage_smtp(): void {
        $this->getJson('/api/v1/admin/mail-settings')->assertUnauthorized();
        $this->actingAs($this->user('staff'))->putJson('/api/v1/admin/mail-settings',$this->payload())->assertForbidden();
        $this->postJson('/api/v1/admin/mail-settings/test')->assertForbidden();
    }
    public function test_saved_settings_are_encrypted_applied_and_never_return_password(): void {
        $this->actingAs($this->user('system_admin'));
        $this->putJson('/api/v1/admin/mail-settings',$this->payload())->assertOk()->assertJsonPath('password_set',true)->assertJsonMissingPath('password');
        $raw=DB::table('platform_mail_settings')->value('settings');
        $this->assertStringNotContainsString('secret-only-in-test',$raw);
        $this->assertStringNotContainsString('smtp.example.test',$raw);
        $this->getJson('/api/v1/admin/mail-settings')->assertOk()->assertJsonMissingPath('password');
        $this->assertSame('smtp',config('mail.default'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertSame('smtp.example.test',config('mail.mailers.smtp.host'));
        $this->putJson('/api/v1/admin/mail-settings',[...$this->payload(),'password'=>''])->assertOk();
        $this->assertSame('secret-only-in-test',app(Settings::class)->read()['password']);
        $this->putJson('/api/v1/admin/mail-settings',[...$this->payload(),'clear_password'=>true,'enabled'=>false])->assertOk()->assertJsonPath('password_set',false);
        $this->assertSame('log',config('mail.default'));
        $this->assertDatabaseHas('audit_entries',['action'=>'mail.settings_saved']);
    }
    public function test_invalid_security_and_host_are_rejected(): void {
        $this->actingAs($this->user('system_admin'));
        $this->putJson('/api/v1/admin/mail-settings',[...$this->payload(),'security'=>'none','host'=>'smtp://user:pass@host'])->assertUnprocessable()->assertJsonValidationErrors(['host','security']);
        $this->postJson('/api/v1/admin/mail-settings/test')->assertUnprocessable();
    }
}
