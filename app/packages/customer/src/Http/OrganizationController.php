<?php
namespace App\Modules\Customer\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use App\Contracts\Module\AuditSink;
class OrganizationController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
    private function authorize(Request $r): void
    {
        abort_unless($r->user()->active && $r->user()->role === 'system_admin', 403);
    }
    public function index(Request $r)
    {
        $this->authorize($r);
        return $this->db->table('customer_organizations')->orderBy('name')->get();
    }
    public function save(Request $r, ?int $id = null)
    {
        $this->authorize($r);
        $d = $r->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:customer_organizations,id',
            'version' => $id ? 'required|integer|min:1' : 'sometimes|integer',
        ]);
        return $this->db->transaction(function () use ($r, $id, $d) {
            // Lock the full hierarchy in one stable order so concurrent reparenting cannot create cycles.
            $rows = $this->db
                ->table('customer_organizations')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($id) {
                abort_unless(isset($rows[$id]), 404);
                abort_unless(
                    (int) $rows[$id]->version === (int) $d['version'],
                    409,
                    'Organisation wurde inzwischen geändert.',
                );
            }
            $parent = $d['parent_id'] ?? null;
            $seen = [];
            while ($parent) {
                abort_if(
                    $parent == $id || isset($seen[$parent]),
                    422,
                    'Organisation kann nicht unter sich selbst eingeordnet werden.',
                );
                abort_unless(isset($rows[$parent]), 409, 'Übergeordnete Organisation wurde geändert.');
                $seen[$parent] = true;
                $parent = $rows[$parent]->parent_id;
            }
            $values = ['name' => $d['name'], 'parent_id' => $d['parent_id'] ?? null, 'updated_at' => now()];
            if ($id) {
                $this->db
                    ->table('customer_organizations')
                    ->where('id', $id)
                    ->update([...$values, 'version' => $rows[$id]->version + 1]);
            } else {
                $id = $this->db
                    ->table('customer_organizations')
                    ->insertGetId([...$values, 'created_at' => now()]);
            }
            $this->audit->record('organization.saved', $id);
            return ['id' => $id];
        }, 3);
    }
    public function assign(Request $r, int $id)
    {
        $this->authorize($r);
        $d = $r->validate(['organization_id' => 'nullable|integer|exists:customer_organizations,id']);
        return $this->db->transaction(function () use ($id, $d) {
            if ($d['organization_id'] ?? null) {
                abort_unless(
                    $this->db
                        ->table('customer_organizations')
                        ->where('id', $d['organization_id'])
                        ->lockForUpdate()
                        ->first(),
                    409,
                );
            }
            abort_unless($this->db->table('tenants')->where('id', $id)->lockForUpdate()->first(), 404);
            $this->db
                ->table('tenants')
                ->where('id', $id)
                ->update(['organization_id' => $d['organization_id'] ?? null, 'updated_at' => now()]);
            $this->audit->record('organization.tenant_assigned', $id, $id);
            return ['updated' => true];
        });
    }
    public function delete(Request $r, int $id)
    {
        $this->authorize($r);
        return $this->db->transaction(function () use ($id) {
            $rows = $this->db->table('customer_organizations')->orderBy('id')->lockForUpdate()->get();
            abort_unless($rows->firstWhere('id', $id), 404);
            abort_if(
                $rows->where('parent_id', $id)->isNotEmpty() ||
                    $this->db->table('tenants')->where('organization_id', $id)->exists(),
                409,
                'Organisation enthält noch Unterorganisationen oder Restaurants.',
            );
            $this->db->table('customer_organizations')->where('id', $id)->delete();
            $this->audit->record('organization.deleted', $id);
            return response()->noContent();
        }, 3);
    }
}
