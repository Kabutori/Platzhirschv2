<?php
namespace App\ModuleUpdates;
use Illuminate\Http\Request;
class Distribution
{
    public function handle(Request $r, Settings $settings, Registry $registry, string $path)
    {
        $hash = $settings->read()['reader_hash'];
        abort_unless(
            $hash && $r->bearerToken() && hash_equals($hash, hash('sha256', $r->bearerToken())),
            401,
            'Registry-Token fehlt oder ist ungültig.',
        );
        $base = url('/api/module-registry');
        if ($path === 'composer/packages.json') {
            return response()->json($registry->metadata($base));
        }
        if (str_starts_with($path, 'npm/')) {
            return response()->json($registry->metadata($base, rawurldecode(substr($path, 4))));
        }
        if (str_starts_with($path, 'files/')) {
            return response()->file($registry->file(substr($path, 6)), [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/octet-stream',
            ]);
        }
        abort(404);
    }
}
