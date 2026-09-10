<?php
// Isolated CI rendering of the actual Blade templates; no mail, DNS or provisioning.
require __DIR__ . '/../app/vendor/autoload.php';
$app = require __DIR__ . '/../app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'session.driver' => 'array', 'registration.privacy_url' => 'https://example.org/datenschutz',
    'registration.imprint_url' => 'https://example.org/impressum']);
app('view')->share('errors', new \Illuminate\Support\ViewErrorBag);
try {
$output = __DIR__ . '/../app/public/landing/';
if (!is_dir($output)) mkdir($output, 0755, true);
file_put_contents($output . 'preview-landing.html', view('registration.landing', ['available' => true])->render());
file_put_contents($output . 'preview-confirm.html', view('registration.confirm', [
    'registration' => (object) ['email' => 'kontakt@linde.de', 'business_name' => 'Restaurant Zur Linde', 'website' => 'https://linde.de/'],
    'token' => str_repeat('a', 64),
])->render());
file_put_contents($output . 'preview-expired.html', view('registration.confirm', ['registration' => null, 'token' => str_repeat('a', 64)])->render());
echo "Registrierungsansichten ohne externe Aufrufe gerendert.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
