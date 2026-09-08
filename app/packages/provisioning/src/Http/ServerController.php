<?php
namespace App\Modules\Provisioning\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Modules\Provisioning\Application\ServerProbe;
class ServerController
{
    public function __construct(
        private DatabaseManager $db,
        private Encrypter $crypt,
        private ServerProbe $probe,
        private \App\Contracts\Module\AuditSink $audit,
    ) {}
    private function authorize(Request $r, string $permission): void
    {
        abort_unless($r->user()?->active && $r->user()->hasPermission($permission), 403);
    }
    private function publicRow(object $row): array
    {
        $data = (array) $row;
        unset($data['password']);
        $data['credentials_configured'] = true;
        $data['tls_required'] = (bool) $data['tls_required'];
        return $data;
    }
    public function index(Request $r): array
    {
        $this->authorize($r, 'provisioning.servers.read');
        return [
            'servers' => $this->db
                ->table('prov_db_servers')
                ->orderBy('name')
                ->get()
                ->map(fn($r) => $this->publicRow($r))
                ->all(),
            'provisioning_mode' => 'local',
            'notice' =>
                'Registrierte Verbindungen sind Prüfziele. Neue Restaurants werden weiterhin auf dem lokalen Installationsserver angelegt.',
        ];
    }
    public function save(Request $r, ?int $id = null)
    {
        $this->authorize($r, 'provisioning.servers.manage');
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D'],
            'port' => 'required|integer|min:1|max:65535',
            'region' => 'required|string|max:40',
            'purpose' => ['required', Rule::in(['test', 'primary', 'tenant'])],
            'database' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/D'],
            'username' => 'required|string|max:128',
            'password' => ($id ? 'nullable' : 'required') . '|string|max:1024',
            'tls_required' => 'required|boolean',
            'version' => $id ? 'required|integer|min:1' : 'prohibited',
        ]);
        if (!empty($data['password'])) {
            $data['password'] = $this->crypt->encryptString($data['password']);
        } else {
            unset($data['password']);
        }
        return $this->db->transaction(function () use ($id, $data) {
            $data['updated_at'] = now();
            if ($id) {
                abort_unless($this->db->table('prov_db_servers')->where('id', $id)->exists(), 404);
                $version = $data['version'];
                $data['version']++;
                abort_unless(
                    $this->db
                        ->table('prov_db_servers')
                        ->where('id', $id)
                        ->where('version', $version)
                        ->update($data),
                    409,
                    'Der Server wurde zwischenzeitlich geändert. Bitte neu laden.',
                );
            } else {
                $data['created_at'] = now();
                $id = $this->db->table('prov_db_servers')->insertGetId($data);
            }
            $this->audit->record('provisioning.server_saved', (string) $id);
            return response()->json($this->publicRow($this->db->table('prov_db_servers')->find($id)), 200);
        });
    }
    public function test(Request $r, int $id): array
    {
        $this->authorize($r, 'provisioning.servers.test');
        $data = $r->validate(['check' => ['required', Rule::in(['connection', 'permissions'])]]);
        $row = $this->db->table('prov_db_servers')->find($id);
        abort_unless($row, 404);
        $server = (array) $row;
        $server['password'] = $this->crypt->decryptString($server['password']);
        $result = $this->probe->run($server, $data['check'] === 'permissions');
        $this->audit->record(
            'provisioning.server_test.' . $data['check'] . ($result['ok'] ? '.passed' : '.failed'),
            (string) $id,
        );
        return $result;
    }
}
