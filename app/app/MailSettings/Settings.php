<?php
namespace App\MailSettings;
use Illuminate\Support\Facades\{Crypt, DB, Schema};
class Settings
{
    public function read(): ?array {
        if (!Schema::hasTable('platform_mail_settings')) return null;
        $row = DB::table('platform_mail_settings')->find(1);
        return $row ? json_decode(Crypt::decryptString($row->settings), true, flags: JSON_THROW_ON_ERROR) : null;
    }
    public function apply(): void {
        $s = $this->read();
        if (!$s) return;
        config(['mail.default' => $s['enabled'] ? 'smtp' : 'log',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => $s['security'] === 'tls' ? 'smtps' : 'smtp',
                'host' => $s['host'], 'port' => $s['port'], 'username' => $s['username'],
                'password' => $s['password'], 'require_tls' => true, 'timeout' => 10],
            'mail.from' => ['address' => $s['from_address'], 'name' => $s['from_name']]]);
        app('mail.manager')->forgetMailers();
    }
    public function handle($request, \Closure $next) {
        if ($request->is('api/*', '/', 'registrierung', 'registrierung/*')) $this->apply();
        return $next($request);
    }
}
