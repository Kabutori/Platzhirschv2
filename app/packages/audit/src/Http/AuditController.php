<?php
namespace App\Modules\Audit\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class AuditController
{
    public function __construct(private DatabaseManager $db) {}
    public function index(Request $r)
    {
        $v = $r->validate([
            'scope' => ['nullable', Rule::in(['all', 'platform', 'tenant'])],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = $this->db->table('audit_entries');
        if (($v['scope'] ?? 'all') === 'platform') {
            $q->whereNull('tenant_id');
        }
        if (($v['scope'] ?? 'all') === 'tenant') {
            $q->whereNotNull('tenant_id');
        }
        if (isset($v['tenant_id'])) {
            $q->where('tenant_id', $v['tenant_id']);
        }
        if (!empty($v['search'])) {
            $term = $v['search'];
            $q->where(
                fn($q) => $q
                    ->where('action', 'like', '%' . $term . '%')
                    ->orWhere('resource', 'like', '%' . $term . '%'),
            );
        }
        return $q->latest('id')->paginate(100)->withQueryString();
    }
}
