<?php
namespace App\MailSettings;
use Illuminate\Support\Facades\{Crypt, DB, Schema};
class Settings
{
    public static function isWpoven(string $host): bool {
        return strtolower(rtrim($host, '.')) === 'smtp.freesmtpservers.com';
    }
    public function defaults(): array {
        $host = config('mail.mailers.smtp.host') ?: 'smtp.freesmtpservers.com';
        $test = self::isWpoven($host);
        return ['enabled' => !$test && config('mail.default') === 'smtp', 'host' => $host,
            'port' => $test ? 25 : (int) config('mail.mailers.smtp.port', 587),
            'security' => $test ? 'wpoven-test' : config('mail.mailers.smtp.security', 'starttls'),
            'username' => config('mail.mailers.smtp.username') ?? '', 'password' => config('mail.mailers.smtp.password') ?? '',
            'from_address' => config('mail.from.address'), 'from_name' => config('mail.from.name', 'Platzhirsch')];
    }
    public function read(): ?array {
        if (!Schema::hasTable('platform_mail_settings')) return null;
        $row = DB::table('platform_mail_settings')->find(1);
        return $row ? json_decode(Crypt::decryptString($row->settings), true, flags: JSON_THROW_ON_ERROR) : null;
    }
    public function apply(): void {
        $s = $this->read();
        if (!$s) {
            if (self::isWpoven((string) config('mail.mailers.smtp.host'))) config(['mail.default' => 'log']);
            return;
        }
        $test = self::isWpoven($s['host']);
        config(['mail.default' => $s['enabled'] && !$test ? 'smtp' : 'log',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => $s['security'] === 'tls' ? 'smtps' : 'smtp',
                'host' => $s['host'], 'port' => $s['port'], 'username' => $s['username'],
                'password' => $s['password'], 'auto_tls' => !$test, 'require_tls' => !$test, 'timeout' => 10],
            'mail.from' => ['address' => $s['from_address'], 'name' => $s['from_name']]]);
        app('mail.manager')->forgetMailers();
    }
    public function handle($request, \Closure $next) {
        if ($request->is('api/*', '/', 'registrierung', 'registrierung/*')) $this->apply();
        return $next($request);
    }
}
