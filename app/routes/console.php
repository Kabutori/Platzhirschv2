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

Artisan::command('module:repair {operation} {--acknowledge-partial-migrations}', function () {
    if (!$this->option('acknowledge-partial-migrations')) {
        $this->error(
            'Zuerst teilweise ausgeführte Migrationen prüfen. Danach mit --acknowledge-partial-migrations erneut starten. Es werden keine Tabellen gelöscht oder Daten zurückgesetzt.',
        );
        return 1;
    }
    $op = DB::table('tenant_operations')->find((int) $this->argument('operation'));
    if (!$op || $op->kind !== 'module_enable' || $op->status !== 'failed') {
        $this->error('Kein fehlgeschlagener Modulauftrag.');
        return 1;
    }
    $database = app(\App\Services\TenantDatabase::class);
    try {
        $database->lock($op->tenant_id);
        $tenant = \App\Models\Tenant::findOrFail($op->tenant_id);
        if ($tenant->status !== 'upgrading' || $tenant->placement_version != $op->expected_version) {
            throw new \RuntimeException('state_changed');
        }
        [$pdo, $host] = app(\App\Services\ProvisioningConnection::class)->open($tenant->server_id);
        if (!preg_match('/^ph_t_[a-f0-9]{24}$/D', $tenant->database_name)) {
            throw new \RuntimeException('identifier_invalid');
        }
        $account = $pdo->quote($tenant->database_user) . '@' . $pdo->quote($host);
        $grant = str_replace('_', '\\_', $tenant->database_name);
        $matched = false;
        // Inspect only the tenant's own grants. The worker deliberately has no
        // SELECT privilege on MySQL's system tables to inspect other accounts.
        $database->connect($tenant, true);
        $grants = DB::connection('tenant')->getPdo()->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($grants as $line) {
            if (
                !preg_match('/^GRANT (.+?) ON `([^`]+)`\.\* TO /', $line, $m) ||
                str_replace('\\', '', $m[2]) !== $tenant->database_name
            ) {
                continue;
            }
            $privileges = array_map('trim', explode(',', $m[1]));
            $allowed = [
                'SELECT',
                'INSERT',
                'UPDATE',
                'DELETE',
                'CREATE',
                'ALTER',
                'INDEX',
                'DROP',
                'REFERENCES',
            ];
            if (array_diff($privileges, $allowed)) {
                throw new \RuntimeException('unexpected_privileges');
            }
            $ddl = array_intersect($privileges, ['CREATE', 'ALTER', 'INDEX', 'DROP', 'REFERENCES']);
            if ($ddl) {
                $pdo->exec('REVOKE ' . implode(',', $ddl) . " ON `$grant`.* FROM $account");
            }
            $matched = true;
        }
        if (!$matched) {
            throw new \RuntimeException('grant_not_found');
        }
        DB::transaction(function () use ($tenant, $op) {
            $current = \App\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'upgrading' || $current->placement_version != $op->expected_version) {
                throw new \RuntimeException('state_changed');
            }
            app(\App\Modules\Billing\PublicApi\Entitlements::class)->finish(
                $tenant->id,
                $op->module_code,
                'error',
                '',
            );
            $current->update(['status' => 'active']);
            DB::table('tenant_operations')
                ->where('id', $op->id)
                ->update(['error_code' => 'repaired_activation_retry_required', 'updated_at' => now()]);
            \App\Services\Audit::record('module.repaired', $op->id, $tenant->id);
        });
        $this->info(
            'DDL-Rechte entzogen; Restaurant freigegeben. Das Modul bleibt gesperrt und kann nach Prüfung seiner Migration erneut aktiviert werden.',
        );
        return 0;
    } catch (\Throwable) {
        $this->error(
            'Reparatur nicht abgeschlossen. Auftrag, Serverzugang und Datenbankrechte lokal prüfen.',
        );
        return 1;
    } finally {
        $database->disconnect();
    }
});

Artisan::command('reservation:notifications', function () {
    \App\Models\Tenant::where('status', 'active')
        ->select('id')
        ->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                try {
                    app(\App\Contracts\Module\TenantRuntime::class)->withTenant($tenant->id, function (
                        $context,
                    ) {
                        app(\App\Modules\Reservation\Application\ReservationNotifications::class)->dispatch(
                            $context->name,
                            $context->timezone,
                        );
                    });
                } catch (\Throwable) {
                    /* Status remains inspectable; never print credentials or message bodies. */
                }
            }
        });
})->purpose('Fällige Reservierungsnachrichten zustellen');
Schedule::command('reservation:notifications')->everyMinute()->withoutOverlapping(10);
// Provider requests share the same 30-minute cache and five-minute retry guard
// as the UI. The installed Windows scheduler also runs this with no browser open.
Schedule::command('weather:refresh')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('registration:check-website {website} {email}', function () {
    try {
        $result = app(\App\Registration\WebsiteCheck::class)->check($this->argument('website'), $this->argument('email'));
        $this->info('Branche: ' . $result['category'] . '; Begriffe: ' . implode(', ', $result['keywords']));
        return 0;
    } catch (\Illuminate\Validation\ValidationException $e) {
        foreach ($e->errors() as $messages) foreach ($messages as $message) $this->error($message);
        return 1;
    }
})->purpose('Website und E-Mail-Domain ohne Kontoanlage oder Mailversand prüfen');
Artisan::command('registration:prune', function () {
    DB::table('registration_requests')->whereNull('verified_at')->where('expires_at', '<=', now())->delete();
    $this->info('Abgelaufene, unbestätigte Registrierungen entfernt.');
});
Schedule::command('registration:prune')->daily()->withoutOverlapping();
