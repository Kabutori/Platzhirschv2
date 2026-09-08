<?php
namespace App\Modules\Provisioning\Http;
use App\Contracts\Module\AuditSink;
use App\Modules\Provisioning\PublicApi\InstalledDatabaseAccess;
use Illuminate\Http\Request;
use Illuminate\Contracts\Hashing\Hasher;
class DatabaseAccessController
{
    public function __construct(
        private InstalledDatabaseAccess $databases,
        private Hasher $hash,
        private AuditSink $audit,
    ) {}
    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->role === 'system_admin' && $request->user()->active, 403);
    }
    public function index(Request $request)
    {
        $this->authorize($request);
        return response()
            ->json($this->databases->connections())
            ->header('Cache-Control', 'no-store, private');
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
        if (!$this->hash->check($data['password'], $request->user()->password)) {
            $this->audit->record('database.credentials_denied', 'reauthentication');
            abort(422, 'Administratorkennwort ist nicht korrekt.');
        }
        $credential = $this->databases->credential($connection);
        $this->audit->record('database.credentials_revealed', $connection);
        return response()
            ->json(['password' => $credential['password'], 'visible_seconds' => 30])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
