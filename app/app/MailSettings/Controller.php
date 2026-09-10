<?php
namespace App\MailSettings;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
class Controller
{
    public function index(Settings $settings) {
        $s = $settings->read() ?? ['enabled' => false, 'host' => '', 'port' => 587, 'security' => 'starttls',
            'username' => '', 'password' => '', 'from_address' => '', 'from_name' => 'Platzhirsch'];
        $s['password_set'] = $s['password'] !== '';
        unset($s['password']);
        return response()->json($s);
    }
    public function save(Request $r, Settings $settings) {
        $v = $r->validate(['enabled' => 'required|boolean', 'host' => ['required','string','max:253','regex:/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/D'],
            'port' => 'required|integer|between:1,65535', 'security' => 'required|in:starttls,tls',
            'username' => 'nullable|string|max:254', 'password' => 'nullable|string|max:1024',
            'clear_password' => 'sometimes|boolean', 'from_address' => 'required|email|max:254', 'from_name' => 'required|string|max:120']);
        DB::transaction(function () use ($v, $settings) {
            DB::table('platform_mail_settings')->where('id', 1)->lockForUpdate()->first();
            $old = $settings->read();
            $v['password'] = ($v['clear_password'] ?? false) ? '' : (($v['password'] ?? '') !== '' ? $v['password'] : ($old['password'] ?? ''));
            $v['username'] = $v['username'] ?? '';
            unset($v['clear_password']);
            DB::table('platform_mail_settings')->updateOrInsert(['id' => 1],
                ['settings' => Crypt::encryptString(json_encode($v, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('mail.settings_saved', 1);
        });
        $settings->apply();
        return $this->index($settings);
    }
    public function test(Settings $settings) {
        abort_unless($settings->read(), 422, 'Bitte zuerst die SMTP-Einstellungen speichern.');
        $settings->apply();
        $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
        try {
            $transport->start();
            Audit::record('mail.connection_tested', 1);
            return response()->json(['message' => 'TLS-Verbindung und SMTP-Anmeldung erfolgreich. Es wurde keine E-Mail versendet.']);
        } catch (\Throwable) {
            return response()->json(['message' => 'SMTP-Verbindung fehlgeschlagen. Server, Port, TLS und Zugangsdaten prüfen.'], 422);
        } finally {
            try { $transport->stop(); } catch (\Throwable) {}
        }
    }
}
