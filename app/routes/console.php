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
