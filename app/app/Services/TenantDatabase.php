<?php
namespace App\Services;
use App\Models\Tenant;
use App\Modules\Provisioning\PublicApi\ServerDirectory;
use Illuminate\Support\Facades\DB;
class TenantDatabase
{
    private ?\PDO $lockConnection = null;
    private ?string $lockName = null;
    public function lock(int $id): void
    {
        if ($this->lockConnection) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $pdo = DB::connection()->getPdo();
        $name = 'ph_tenant_' . $id;
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            abort(503, 'Restaurant wird gerade gewartet. Bitte erneut versuchen.');
        }
        $this->lockConnection = $pdo;
        $this->lockName = $name;
    }
    public function configuration(Tenant $tenant): array
    {
        $base = config('database.connections.mysql');
        if ($tenant->server_id) {
            $base = array_replace($base, app(ServerDirectory::class)->connection($tenant->server_id));
        }
        return array_replace($base, [
            'database' => $tenant->database_name,
            'username' => $tenant->database_user,
            'password' => $tenant->database_password,
        ]);
    }
    public function connect(Tenant $tenant, bool $maintenance = false): void
    {
        $this->lock($tenant->id);
        try {
            $tenant->refresh();
            if (!$maintenance) {
                abort_unless($tenant->status === 'active', 503, 'Restaurant wird gerade gewartet.');
            }
            if (!preg_match('/^ph_t_[a-f0-9]{24}$/D', $tenant->database_name)) {
                throw new \RuntimeException('Invalid tenant database');
            }
            config(['database.connections.tenant' => $this->configuration($tenant)]);
            DB::purge('tenant');
        } catch (\Throwable $e) {
            $this->disconnect();
            throw $e;
        }
    }
    public function disconnect(): void
    {
        DB::purge('tenant');
        config(['database.connections.tenant' => config('database.connections.mysql')]);
        if ($this->lockConnection) {
            try {
                $s = $this->lockConnection->prepare('SELECT RELEASE_LOCK(?)');
                $s->execute([$this->lockName]);
            } finally {
                $this->lockConnection = null;
                $this->lockName = null;
            }
        }
    }
}
