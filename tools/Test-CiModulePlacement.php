<?php
// Disposable CI fixture only. Never accepts or prints production credentials.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted') {
    exit(2);
}
$root = $argv[1] ?? '';
if ($root !== 'C:\\ph-ci') {
    exit(2);
}
require $root . '/app/vendor/autoload.php';
$app = require $root . '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Artisan};
use App\Models\Tenant;
use App\Services\Totp;
$clients = [];
function check(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}
function callApi(
    string $portal,
    string $method,
    string $path,
    ?array $body = null,
    int $expected = 200,
): array {
    global $clients;
    if (!isset($clients[$portal])) {
        $clients[$portal] = curl_init();
        curl_setopt($clients[$portal], CURLOPT_COOKIEFILE, '');
    }
    $client = $clients[$portal];
    $headers = ['Accept: application/json', 'X-Platzhirsch-Portal: ' . $portal];
    if ($method !== 'GET') {
        $csrf = callApi($portal, 'GET', 'csrf');
        $headers[] = 'X-CSRF-TOKEN: ' . $csrf['token'];
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($client, [
        CURLOPT_URL => 'http://127.0.0.1:8378/api/' . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $response = curl_exec($client);
    check(
        curl_getinfo($client, CURLINFO_RESPONSE_CODE) === $expected,
        'http_' . str_replace('/', '_', $path) . '_' . curl_getinfo($client, CURLINFO_RESPONSE_CODE),
    );
    return json_decode($response, true) ?? [];
}
function waitTenant(int $id): Tenant
{
    for ($i = 0; $i < 90; $i++) {
        $t = Tenant::findOrFail($id);
        if ($t->status === 'active') {
            return $t;
        }
        check(!in_array($t->status, ['failed', 'blocked'], true), 'tenant_failed');
        usleep(1000000);
    }
    throw new RuntimeException('tenant_timeout');
}
try {
    $password = getenv('PH_CI_PASSWORD');
    check(is_string($password) && strlen($password) > 12, 'missing_test_password');
    callApi('administration', 'POST', 'v1/admin/auth/login', [
        'email' => 'admin@example.test',
        'password' => $password,
    ]);
    callApi('restaurant', 'POST', 'v1/admin/auth/login', [
        'email' => 'demo-owner@example.test',
        'password' => $password,
    ]);
    callApi('administration', 'PATCH', 'v1/admin/billing/products/reporting', [
        'amount_cents' => 1900,
        'available' => true,
    ]);
    $order = callApi(
        'restaurant',
        'POST',
        'v1/restaurant/modules/orders',
        ['module_code' => 'reporting', 'request_key' => (string) Illuminate\Support\Str::uuid()],
        201,
    );
    callApi('restaurant', 'POST', 'v1/restaurant/modules/reporting/activation', ['enabled' => true], 422);
    callApi('administration', 'POST', 'v1/admin/billing/orders/' . $order['id'] . '/confirm', [
        'payment_reference' => 'ci-fixture-no-real-payment',
        'payment_confirmed' => true,
    ]);
    $activation = callApi(
        'restaurant',
        'POST',
        'v1/restaurant/modules/reporting/activation',
        ['enabled' => true],
        202,
    );
    $demo = Tenant::where('is_demo', true)->firstOrFail();
    waitTenant($demo->id);
    check(
        DB::table('tenant_operations')->find($activation['operation_id'])->status === 'completed',
        'activation_failed',
    );
    $date = date('Y-m-d');
    $saved = callApi(
        'restaurant',
        'POST',
        'v1/restaurant/reporting/saved',
        ['name' => 'Move verification', 'from' => $date, 'to' => $date],
        201,
    );
    $before = callApi('restaurant', 'GET', 'v1/restaurant/tables');
    check(count($before) === 3, 'demo_tables_before');
    callApi('restaurant', 'POST', 'v1/restaurant/modules/reporting/activation', ['enabled' => false]);
    callApi('restaurant', 'GET', 'v1/restaurant/reporting?from=' . $date . '&to=' . $date, null, 403);
    callApi('restaurant', 'POST', 'v1/restaurant/modules/reporting/activation', ['enabled' => true], 202);
    waitTenant($demo->id);
    // A second independent MySQL data directory, bound only to loopback by the parent script.
    $secret = json_decode(
        file_get_contents($root . '/ci-second-credentials.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $second = new PDO('mysql:host=127.0.0.1;port=3309;charset=utf8mb4', 'root', $secret['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $second->exec('CREATE DATABASE ph_probe_check');
    $probe = bin2hex(random_bytes(24));
    $second->exec("CREATE USER 'ph_probe'@'127.0.0.1' IDENTIFIED BY " . $second->quote($probe));
    $second->exec("GRANT SELECT ON ph_probe_check.* TO 'ph_probe'@'127.0.0.1'");
    $server = callApi('administration', 'POST', 'v1/admin/database-servers', [
        'name' => 'CI second instance',
        'host' => '127.0.0.1',
        'port' => 3309,
        'region' => 'CI',
        'purpose' => 'tenant',
        'database' => 'ph_probe_check',
        'username' => 'ph_probe',
        'password' => $probe,
        'tls_required' => false,
    ]);
    check(
        Artisan::call('server:authorize', [
            'server' => $server['id'],
            '--credentials-file' => $root . '/ci-second-credentials.json',
        ]) === 0,
        'authorize_failed',
    );
    $assigned = callApi(
        'administration',
        'POST',
        'v1/admin/tenants',
        [
            'name' => 'Assigned directly',
            'email' => 'assigned@example.test',
            'timezone' => 'Europe/Berlin',
            'server_id' => $server['id'],
        ],
        202,
    );
    $placed = waitTenant($assigned['id']);
    check($placed->server_id === $server['id'], 'assignment_missing');
    $probeTenant = new PDO(
        'mysql:host=127.0.0.1;port=3309;dbname=' . $placed->database_name,
        $placed->database_user,
        $placed->database_password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    check($probeTenant->query('SELECT COUNT(*) FROM rooms')->fetchColumn() == 0, 'assigned_schema_missing');
    $setup = callApi('administration', 'POST', 'v1/admin/auth/mfa/begin', ['password' => $password]);
    $step = intdiv(time(), 30);
    callApi('administration', 'POST', 'v1/admin/auth/mfa/confirm', [
        'code' => Totp::code($setup['secret'], $step),
    ]);
    $demo->refresh();
    $old = $demo->database_name;
    $move = callApi(
        'administration',
        'POST',
        'v1/admin/tenants/' . $demo->id . '/move',
        [
            'target_server_id' => $server['id'],
            'placement_version' => $demo->placement_version,
            'password' => $password,
            'code' => Totp::code($setup['secret'], $step + 1),
            'backup_confirmed' => true,
            'downtime_confirmed' => true,
        ],
        202,
    );
    $moved = waitTenant($demo->id);
    check(DB::table('tenant_operations')->find($move['operation_id'])->status === 'completed', 'move_failed');
    check($moved->server_id === $server['id'] && $moved->database_name !== $old, 'placement_not_changed');
    $after = callApi('restaurant', 'GET', 'v1/restaurant/tables');
    check($before === $after, 'moved_data_changed');
    $report = callApi('restaurant', 'GET', 'v1/restaurant/reporting?from=' . $date . '&to=' . $date);
    check(
        count($report['saved']) === 1 && $report['saved'][0]['id'] === $saved['id'],
        'module_data_not_moved',
    );
    $source = app(App\Services\ProvisioningConnection::class)->open(null)[0];
    check(
        $source->query('SELECT COUNT(*) FROM `' . $old . '`.dining_tables')->fetchColumn() == 3,
        'source_not_retained',
    );
    $targetConnection = new PDO(
        'mysql:host=127.0.0.1;port=3309;dbname=' . $moved->database_name,
        $moved->database_user,
        $moved->database_password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $denied = false;
    try {
        $targetConnection->exec('CREATE TABLE forbidden_ddl (id INT)');
    } catch (PDOException) {
        $denied = true;
    }
    check($denied, 'tenant_has_ddl');
    echo "Module/placement integration passed: external payment confirmation, activation migration, disable, reactivation, second-server provisioning, verified move, retained source and DML-only credentials.\n";
} catch (Throwable $e) {
    // Known fixture messages only, never exception text from PDO, HTTP bodies or credentials.
    $message =
        $e instanceof RuntimeException &&
        preg_match('/^(http_[a-zA-Z0-9_?=&.-]+|[a-z_]+)$/D', $e->getMessage())
            ? $e->getMessage()
            : 'fixture_failed';
    fwrite(
        STDERR,
        'Module/placement test: ' . $message . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n",
    );
    exit(1);
} finally {
    foreach ($clients as $client) {
        curl_close($client);
    }
}
