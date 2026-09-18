<?php
namespace App\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use App\Services\Audit;
class Settings
{
    public function read(): array
    {
        $s = Schema::hasTable('platform_registration_settings')
            ? DB::table('platform_registration_settings')->find(1)
            : null;
        return $s
            ? [
                'enabled' => (bool) $s->enabled,
                'privacy_url' => $s->privacy_url,
                'imprint_url' => $s->imprint_url,
                'revision' => $s->revision,
            ]
            : [
                'enabled' => (bool) config('registration.enabled'),
                'privacy_url' => config('registration.privacy_url'),
                'imprint_url' => config('registration.imprint_url'),
                'revision' => 0,
            ];
    }
    public function apply(): void
    {
        foreach ($this->read() as $k => $v) {
            if ($k !== 'revision') {
                config(['registration.' . $k => $v]);
            }
        }
    }
    public function index(): array
    {
        $this->apply();
        return [
            ...$this->read(),
            'available' => RegistrationController::available(),
            'https_ready' => str_starts_with((string) config('app.url'), 'https://'),
            'smtp_ready' => config('mail.default') === 'smtp',
            'public_url' => rtrim(config('app.url'), '/') . '/registrierung',
        ];
    }
    public function save(Request $r): array
    {
        $d = $r->validate([
            'enabled' => 'required|boolean',
            'privacy_url' => 'required|url:https|max:1000',
            'imprint_url' => 'required|url:https|max:1000',
            'revision' => 'required|integer|min:0',
        ]);
        if ($d['enabled']) {
            abort_unless(
                config('mail.default') === 'smtp' && str_starts_with((string) config('app.url'), 'https://'),
                422,
                'Zuerst HTTPS-Anwendungsadresse und produktiven SMTP-Versand einrichten.',
            );
        }
        DB::transaction(function () use ($d) {
            DB::table('bootstrap_state')->where('id', 1)->lockForUpdate()->first();
            $s = DB::table('platform_registration_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(
                (int) ($s->revision ?? 0) === (int) $d['revision'],
                409,
                'Registrierungseinstellungen wurden geändert. Neu laden.',
            );
            DB::table('platform_registration_settings')->updateOrInsert(
                ['id' => 1],
                [
                    ...$d,
                    'revision' => $d['revision'] + 1,
                    'created_at' => $s->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            Audit::record('registration.settings_saved', 1);
        });
        return $this->index();
    }
    public function handle($request, \Closure $next)
    {
        if ($request->is('api/*', '/', 'registrierung', 'registrierung/*')) {
            $this->apply();
        }
        return $next($request);
    }
}
