<?php
namespace App\Http\Controllers;
use App\Models\Tenant;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
class DatabaseAccessController
{
    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->role === 'system_admin' && $request->user()->active, 403);
    }
    public function index(Request $request)
    {
        $this->authorize($request);
        $config = config('database.connections.mysql');
        $rows = [
            [
                'id' => 'platform',
                'name' => 'Plattform-Datenbank',
                'scope' => 'Plattform',
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $config['database'],
                'username' => $config['username'],
                'status' => 'active',
            ],
        ];
        foreach (
            Tenant::whereIn('status', ['active', 'suspended'])
                ->orderBy('name')
                ->get()
            as $tenant
        ) {
            $rows[] = [
                'id' => (string) $tenant->id,
                'name' => $tenant->name,
                'scope' => 'Restaurant',
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $tenant->database_name,
                'username' => $tenant->database_user,
                'status' => $tenant->status,
            ];
        }
        return response()->json($rows)->header('Cache-Control', 'no-store, private');
    }
    public function reveal(Request $request, string $connection)
    {
        $this->authorize($request);
        abort_unless(
            $request->isSecure() ||
                (in_array($request->ip(), ['127.0.0.1', '::1'], true) &&
                    in_array($request->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true)),
            403,
            'Passwörter nur über HTTPS oder lokal anzeigen.',
        );
        $data = $request->validate(['password' => 'required|string|max:1024']);
        if (!Hash::check($data['password'], $request->user()->password)) {
            Audit::record('database.credentials_denied');
            abort(422, 'Administratorkennwort ist nicht korrekt.');
        }
        if ($connection === 'platform') {
            $secret = config('database.connections.mysql.password');
            $tenantId = null;
        } else {
            abort_unless(ctype_digit($connection), 404);
            $tenant = Tenant::whereIn('status', ['active', 'suspended'])->findOrFail($connection);
            $secret = $tenant->database_password;
            $tenantId = $tenant->id;
        }
        Audit::record('database.credentials_revealed', $connection, $tenantId);
        return response()
            ->json(['password' => $secret, 'visible_seconds' => 30])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
