<?php
use Illuminate\Support\Facades\{Artisan, Schedule, DB, Hash};
use App\Models\User;
Schedule::call(fn() => cache()->put('scheduler_last_seen', now()->toIso8601String(), 180))->everyMinute();
Schedule::command('queue:prune-failed --hours=168')->daily();
Artisan::command('platform:bootstrap-token', function () {
    if (DB::table('bootstrap_state')->where('id', 1)->value('completed')) {
        $this->error('Einrichtung bereits abgeschlossen.');
        return 2;
    }
    $token = bin2hex(random_bytes(32));
    $this->line('Einmal-Schlüssel: ' . $token);
    $this->line('BOOTSTRAP_TOKEN_HASH=' . hash('sha256', $token));
})->purpose('Einrichtungsschlüssel für lokale Installation erzeugen');
Artisan::command('platform:health', function () {
    DB::select('SELECT 1');
    $this->info('Datenbank erreichbar. Version ' . config('platzhirsch.version'));
    return 0;
});
Artisan::command('platform:recover-admin {email}', function () {
    $user = User::where('email', strtolower($this->argument('email')))
        ->where('role', 'system_admin')
        ->first();
    if (!$user) {
        $this->error('Nicht gefunden.');
        return 1;
    }
    if (!$this->confirm('MFA für dieses Konto zurücksetzen und alle Sitzungen beenden?')) {
        return 1;
    }
    $password = $this->secret('Neues Passwort (mindestens 12 Zeichen)');
    if (strlen($password ?? '') < 12) {
        $this->error('Passwort zu kurz.');
        return 1;
    }
    $user->update(['password' => $password, 'mfa_secret' => null, 'mfa_last_step' => 0, 'active' => true]);
    DB::table('sessions')->where('user_id', $user->id)->delete();
    App\Services\Audit::record('auth.admin_recovered', $user->id);
    $this->info('Zugang zurückgesetzt.');
    return 0;
});

Artisan::command('server:authorize {server} {--credentials-file=}', function () {
    $server = DB::table('prov_db_servers')->find((int) $this->argument('server'));
    if (!$server || !in_array($server->purpose, ['primary', 'tenant'], true)) {
        $this->error('Server ist kein Mandantenziel.');
        return 1;
    }
    if (!$server->tls_required && !in_array($server->host, ['127.0.0.1', 'localhost', '::1'], true)) {
        $this->error('Externe Server benötigen TLS.');
        return 1;
    }
    $source = $this->option('credentials-file');
    if ($source) {
        $credentials = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    } else {
        $credentials = [
            'username' => $this->ask('Provisionierungsbenutzer'),
            'password' => $this->secret('Provisionierungskennwort'),
            'account_host' => $this->ask('IP oder DNS-Name dieses Anwendungsservers aus Sicht von MySQL'),
        ];
    }
    if (
        empty($credentials['username']) ||
        empty($credentials['password']) ||
        !preg_match('/^[a-zA-Z0-9.:-]+$/D', $credentials['account_host'] ?? '')
    ) {
        $this->error('Zugangsdaten unvollständig.');
        return 1;
    }
    $credentials['server_version'] = (int) $server->version;
    $path = storage_path('app/private/server-' . $server->id . '.json');
    if (file_exists($path)) {
        $this->error('Server bereits autorisiert; vorhandene Zugangsdaten bleiben erhalten.');
        return 1;
    }
    $handle = fopen($path, 'x');
    if (!$handle) {
        $this->error('Geschütztes Verzeichnis nicht beschreibbar.');
        return 1;
    }
    try {
        fwrite($handle, json_encode($credentials, JSON_THROW_ON_ERROR));
    } finally {
        fclose($handle);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0600);
    }
    if (
        !DB::table('prov_db_servers')
            ->where('id', $server->id)
            ->where('version', $server->version)
            ->where('provisioning_enabled', false)
            ->update(['provisioning_enabled' => true])
    ) {
        unlink($path);
        $this->error('Server wurde geändert. Freigabe erneut starten.');
        return 1;
    }
    try {
        app(\App\Services\ProvisioningConnection::class)->open($server->id);
    } catch (\Throwable $error) {
        DB::table('prov_db_servers')
            ->where('id', $server->id)
            ->update(['provisioning_enabled' => false]);
        unlink($path);
        $this->error('Serverfreigabe fehlgeschlagen. TLS und Zugangsdaten prüfen.');
        return 1;
    }
    \App\Services\Audit::record('provisioning.server_authorized', $server->id);
    $this->info('Server für Mandanten-Provisionierung freigegeben.');
    return 0;
});
