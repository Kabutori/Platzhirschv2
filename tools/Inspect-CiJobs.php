<?php
// This diagnostic is never part of the installed application or its public routes.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted') {
    throw new RuntimeException('Disposable GitHub test machines only.');
}
$root = $argv[1] ?? '';
require $root . '/app/vendor/autoload.php';
$app = require $root . '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/CiFailureSummary.php';
foreach (Illuminate\Support\Facades\DB::table('failed_jobs')->get(['exception']) as $job) {
    // Never output the exception, SQL, account identifiers, credentials or stack trace.
    echo json_encode(ciFailureSummary($job->exception), JSON_THROW_ON_ERROR) . "\n";
}
