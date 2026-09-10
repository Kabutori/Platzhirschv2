<?php
// Privileged, local CLI only. Never put this helper below public/.
if (PHP_SAPI !== 'cli') exit(1);
[$script, $root, $mode, $folder] = array_pad($argv, 4, null);
if (!$root || !$mode || !$folder) exit(2);
require $root . '/app/vendor/autoload.php';
$app = require $root . '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Artisan};
function document(string $file): array { return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
function putDocument(string $file, array $data): void { if (file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Cannot write recovery document'); }
function schemaName(string $name): string {
    if (!preg_match('/^(platzhirsch_platform|ph_t_[a-f0-9]{24})$/D', $name)) throw new RuntimeException('Unexpected schema');
    return '`' . $name . '`';
}
function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function connectTarget(array $target): PDO {
    if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $target['host']) || $target['port'] < 1 || $target['port'] > 65535) throw new RuntimeException('Invalid database endpoint');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::ATTR_EMULATE_PREPARES => false];
    if (!in_array($target['host'], ['localhost', '127.0.0.1', '::1'], true)) {
        if (empty($target['ca']) || !is_readable($target['ca'])) throw new RuntimeException('Remote database requires trusted CA');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $target['ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    return new PDO('mysql:host=' . $target['host'] . ';port=' . $target['port'] . ';charset=utf8mb4', $target['username'], $target['password'], $options);
}
function inventory(string $root): array {
    $state = document($root . '/installation.json');
    $targets = ['local' => ['host' => '127.0.0.1', 'port' => $state['databasePort'], 'username' => 'root', 'password' => $state['rootPassword'], 'account_host' => '127.0.0.1', 'ca' => null]];
    foreach (DB::table('prov_db_servers')->where('provisioning_enabled', true)->get() as $server) {
        $secret = document($root . '/app/storage/app/private/server-' . $server->id . '.json');
        if (($secret['server_version'] ?? null) !== (int) $server->version) throw new RuntimeException('Stale server authorization');
        // A separate backup account may hold RELOAD/FLUSH_TABLES, SELECT and restore rights.
        $override = $root . '/app/storage/app/private/recovery-server-' . $server->id . '.json';
        if (is_file($override)) $secret = array_replace($secret, document($override));
        $targets['server-' . $server->id] = ['host' => $server->host, 'port' => (int) $server->port,
            'username' => $secret['username'], 'password' => $secret['password'],
            'account_host' => $secret['account_host'] ?? '127.0.0.1', 'ca' => config('platzhirsch.database_server_ca')];
    }
    foreach (DB::table('tenants')->whereNotNull('server_id')->pluck('server_id') as $id) {
        if (!isset($targets['server-' . $id])) throw new RuntimeException('Tenant server is not authorized');
    }
    return $targets;
}
function verifyDump(string $folder): array {
    $manifest = document($folder . '/databases.json');
    if (($manifest['format'] ?? null) !== 1 || empty($manifest['targets'])) throw new RuntimeException('Incomplete database backup');
    foreach ($manifest['targets'] as $key => $target) {
        if (!preg_match('/^(local|server-[0-9]+)$/D', $key)) throw new RuntimeException('Invalid server key');
        foreach ($target['schemas'] as $schema => $tables) {
            schemaName($schema);
            foreach ($tables as $table) {
                if (!preg_match('/^[a-f0-9]{64}\.jsonl$/D', $table['file']) || !hash_equals($table['sha256'], hash_file('sha256', $folder . '/' . $table['file']))) throw new RuntimeException('Corrupt database backup');
            }
        }
    }
    return $manifest;
}
try {
    if ($mode === 'backup') {
        if (DB::table('tenant_operations')->whereIn('status', ['queued','running'])->exists() || DB::table('tenants')->whereIn('status', ['provisioning','upgrading','moving'])->exists()) throw new RuntimeException('Finish pending provisioning or migrations before backup');
        $targets = inventory($root); $connections = []; $manifest = ['format' => 1, 'created_at' => gmdate('c'), 'targets' => []];
        try {
            $seen = [];
            // Acquire every global read lock before exporting the first row. Application is stopped by PowerShell.
            foreach ($targets as $key => $target) {
                $pdo = connectTarget($target);
                $uuid = $pdo->query('SELECT @@server_uuid')->fetchColumn();
                if (isset($seen[$uuid])) throw new RuntimeException('Duplicate database server registration');
                $seen[$uuid] = true;
                $pdo->exec('SET SESSION lock_wait_timeout=30');
                $pdo->exec('FLUSH TABLES WITH READ LOCK');
                $connections[$key] = $pdo;
                $manifest['targets'][$key] = ['uuid' => $uuid, 'version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'schemas' => []];
            }
            foreach ($connections as $key => $pdo) {
                $schemas = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME REGEXP '^ph_t_[a-f0-9]{24}$'" . ($key === 'local' ? " OR SCHEMA_NAME='platzhirsch_platform'" : ''))->fetchAll(PDO::FETCH_COLUMN);
                foreach ($schemas as $schema) {
                    $quoted = schemaName($schema);
                    $extra = $pdo->prepare('SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?) + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?) + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?)');
                    $extra->execute([$schema, $schema, $schema]);
                    if ((int) $extra->fetchColumn()) throw new RuntimeException('Custom triggers, routines or events require an extended backup adapter');
                    $tables = $pdo->query("SHOW FULL TABLES FROM $quoted")->fetchAll(PDO::FETCH_NUM);
                    $manifest['targets'][$key]['schemas'][$schema] = [];
                    foreach ($tables as [$table, $kind]) {
                        if ($kind !== 'BASE TABLE') throw new RuntimeException('Custom database views require an extended backup adapter');
                        $file = hash('sha256', $key . '/' . $schema . '/' . $table) . '.jsonl';
                        $handle = fopen($folder . '/' . $file, 'xb');
                        if (!$handle) throw new RuntimeException('Cannot create table export');
                        try {
                            $create = $pdo->query('SHOW CREATE TABLE ' . $quoted . '.' . identifier($table))->fetch(PDO::FETCH_NUM)[1];
                            $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted . '.' . identifier($table))->fetchAll(PDO::FETCH_ASSOC);
                            $columns = array_column(array_filter($columns, fn($c) => !preg_match('/(VIRTUAL|STORED) GENERATED/', $c['Extra'])), 'Field');
                            $line = json_encode(['table' => $table, 'create' => $create, 'columns' => $columns], JSON_THROW_ON_ERROR) . "\n";
                            if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('Export disk full');
                            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                            $rows = $pdo->query('SELECT ' . implode(',', array_map('identifier', $columns)) . ' FROM ' . $quoted . '.' . identifier($table));
                            $count = 0; $digest = str_repeat("\0", 32);
                            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                                $line = json_encode(array_map(fn($v) => $v === null ? null : base64_encode((string) $v), $row), JSON_THROW_ON_ERROR) . "\n";
                                if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('Export disk full');
                                $digest ^= hash('sha256', $line, true);
                                $count++;
                            }
                            $rows->closeCursor();
                            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                        } finally { fclose($handle); }
                        $manifest['targets'][$key]['schemas'][$schema][] = ['name' => $table, 'file' => $file, 'rows' => $count, 'digest' => bin2hex($digest), 'sha256' => hash_file('sha256', $folder . '/' . $file)];
                    }
                }
            }
            putDocument($folder . '/targets.private.json', $targets);
            putDocument($folder . '/databases.json', $manifest); // Written last; incomplete exports cannot be restored.
        } finally { foreach ($connections as $pdo) { try { $pdo->exec('UNLOCK TABLES'); } catch (Throwable) {} } }
        echo "Coordinated database export complete.\n";
    } elseif ($mode === 'restore' || $mode === 'verify') {
        $manifest = verifyDump($folder);
        if ($mode === 'verify') { echo "Database archive verified.\n"; exit(0); }
        $targets = document($folder . '/targets.private.json');
        $mappingFile = $argv[4] ?? null;
        $fresh = $mappingFile && is_file($mappingFile);
        if ($fresh) {
            $mapping = document($mappingFile);
            if (array_diff(array_keys($targets), array_keys($mapping))) throw new RuntimeException('Recovery mapping must cover every server');
            $targets = $mapping;
        }
        $connections = []; $seen = [];
        foreach ($manifest['targets'] as $key => $saved) {
            $pdo = connectTarget($targets[$key]);
            $uuid = $pdo->query('SELECT @@server_uuid')->fetchColumn();
            if (!$fresh && $uuid !== $saved['uuid']) throw new RuntimeException('Database server identity changed; explicit recovery mapping required');
            if (isset($seen[$uuid])) throw new RuntimeException('Recovery targets must be distinct servers');
            $seen[$uuid] = true;
            if (explode('.', $pdo->query('SELECT VERSION()')->fetchColumn())[0] !== explode('.', $saved['version'])[0]) throw new RuntimeException('Database major version mismatch');
            if ($fresh && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME REGEXP '^ph_t_[a-f0-9]{24}$'")->fetchColumn()) throw new RuntimeException('New-machine targets must not contain restaurant schemas');
            $connections[$key] = $pdo;
        }
        // All files and targets are checked before the first schema change. Application stays stopped on any failure.
        foreach ($manifest['targets'] as $key => $saved) {
            $pdo = $connections[$key]; $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($saved['schemas'] as $schema => $tables) {
                    $quoted = schemaName($schema);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("USE $quoted");
                    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table, $kind]) $pdo->exec('DROP ' . ($kind === 'VIEW' ? 'VIEW ' : 'TABLE ') . identifier($table));
                    foreach ($tables as $table) {
                        $handle = fopen($folder . '/' . $table['file'], 'rb');
                        try {
                            $header = json_decode(fgets($handle), true, 512, JSON_THROW_ON_ERROR);
                            $pdo->exec($header['create']);
                            $insert = $pdo->prepare('INSERT INTO ' . identifier($header['table']) . ' (' . implode(',', array_map('identifier', $header['columns'])) . ') VALUES (' . implode(',', array_fill(0, count($header['columns']), '?')) . ')');
                            $count = 0; $pdo->beginTransaction();
                            while (($line = fgets($handle)) !== false) {
                                $insert->execute(array_map(fn($v) => $v === null ? null : base64_decode($v, true), json_decode($line, true, 512, JSON_THROW_ON_ERROR)));
                                $count++;
                                if ($count % 1000 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
                            }
                            $pdo->commit();
                            if ($count !== $table['rows']) throw new RuntimeException('Restored row count differs');
                            $digest = str_repeat("\0", 32); $verified = 0;
                            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                            $check = $pdo->query('SELECT ' . implode(',', array_map('identifier', $header['columns'])) . ' FROM ' . identifier($header['table']));
                            while ($row = $check->fetch(PDO::FETCH_NUM)) {
                                $line = json_encode(array_map(fn($v) => $v === null ? null : base64_encode((string) $v), $row), JSON_THROW_ON_ERROR) . "\n";
                                $digest ^= hash('sha256', $line, true); $verified++;
                            }
                            $check->closeCursor(); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                            if ($verified !== $count || !hash_equals($table['digest'], bin2hex($digest))) throw new RuntimeException('Restored content differs');
                        } finally { fclose($handle); }
                    }
                }
            } finally { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
        }
        DB::purge();
        // Recreate application accounts; never copy global MySQL/root accounts over destination administrators.
        foreach (\App\Models\Tenant::all() as $tenant) {
            $key = $tenant->server_id ? 'server-' . $tenant->server_id : 'local';
            $pdo = $connections[$key]; $host = $targets[$key]['account_host'];
            if (!preg_match('/^[a-zA-Z0-9.:%_-]+$/D', $host) || !preg_match('/^phu_[a-f0-9]{24}$/D', $tenant->database_user)) throw new RuntimeException('Invalid tenant account');
            $account = $pdo->quote($tenant->database_user) . '@' . $pdo->quote($host);
            $pdo->exec('CREATE USER IF NOT EXISTS ' . $account . ' IDENTIFIED BY ' . $pdo->quote($tenant->database_password));
            $pdo->exec('ALTER USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($tenant->database_password));
            $grant = str_replace('_', '\\_', trim(schemaName($tenant->database_name), '`'));
            $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
            $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$grant`.* TO $account");
        }
        if ($fresh) {
            foreach ($targets as $key => $target) {
                if ($key === 'local') continue;
                $id = (int) substr($key, 7);
                DB::table('prov_db_servers')->where('id', $id)->update(['host' => $target['host'], 'port' => $target['port'], 'tls_required' => !in_array($target['host'], ['localhost','127.0.0.1','::1']), 'version' => DB::raw('version + 1')]);
                $version = (int) DB::table('prov_db_servers')->where('id', $id)->value('version');
                putDocument($root . '/app/storage/app/private/server-' . $id . '.json', ['username' => $target['username'], 'password' => $target['password'], 'account_host' => $target['account_host'], 'server_version' => $version]);
            }
        }
        echo "All database contents and tenant accounts restored.\n";
    } elseif ($mode === 'migrate' || $mode === 'health') {
        $state = document($root . '/installation.json');
        $pdo = connectTarget(['host'=>'127.0.0.1','port'=>$state['databasePort'],'username'=>'root','password'=>$state['rootPassword']]);
        if ($mode === 'migrate') {
            config(['database.connections.mysql.username'=>'root','database.connections.mysql.password'=>$state['rootPassword']]); DB::purge();
            if (Artisan::call('migrate', ['--force'=>true]) !== 0) throw new RuntimeException('Platform migration failed');
        }
        foreach (\App\Models\Tenant::where('status', 'active')->get() as $tenant) {
            $db = app(\App\Services\TenantDatabase::class);
            $db->connect($tenant, true);
            try {
                if ($mode === 'migrate') {
                    [$connection, $accountHost] = app(\App\Services\ProvisioningConnection::class)->open($tenant->server_id);
                    $grant = str_replace('_', '\\_', $tenant->database_name);
                    $account = $connection->quote($tenant->database_user) . '@' . $connection->quote($accountHost);
                    $connection->exec("GRANT CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grant`.* TO $account");
                    try {
                        foreach (app(\App\Core\Module\ModuleRegistry::class)->catalog() as $module) {
                            $definition = app(\App\Core\Module\ModuleRegistry::class)->get($module['code']);
                            $path = $definition->tenantMigrationsPath();
                            if (!$path || !is_dir($path)) continue;
                            if ($module['code'] !== 'reservation' && !DB::table('billing_entitlements')->where('tenant_id', $tenant->id)->where('module_code', $module['code'])->where('status', 'active')->exists()) continue;
                            if (Artisan::call('migrate', ['--database'=>'tenant','--path'=>$path,'--realpath'=>true,'--force'=>true]) !== 0) throw new RuntimeException('Tenant migration failed');
                        }
                    } finally { $connection->exec("REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON `$grant`.* FROM $account"); }
                }
                DB::connection('tenant')->select('SELECT COUNT(*) FROM reservations');
            } finally { $db->disconnect(); }
        }
        echo "Platform and active tenants checked.\n";
    } else { throw new RuntimeException('Unknown operation'); }
} catch (Throwable $error) {
    // PDO exceptions can include connection details. Do not put them in terminal/CI logs.
    fwrite(STDERR, "Database recovery operation failed (" . get_class($error) . ($error instanceof PDOException ? '' : ': ' . $error->getMessage()) . "). Application must remain in maintenance.\n");
    exit(1);
}
