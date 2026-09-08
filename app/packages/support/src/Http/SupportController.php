<?php
namespace App\Modules\Support\Http;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
use App\Contracts\Module\{AuditSink, AccountDirectory, TenantDirectory};
class SupportController
{
    public function __construct(
        private DatabaseManager $db,
        private AuditSink $audit,
        private AccountDirectory $accounts,
        private TenantDirectory $tenants,
    ) {}
    private function scope(Request $r)
    {
        abort_unless($r->user()->hasPermission('support.access'), 403);
        return $this->db
            ->table('support_tickets')
            ->when(!$r->user()->isSystem(), fn($q) => $q->where('tenant_id', $r->user()->tenant_id));
    }
    public function index(Request $r)
    {
        return $this->scope($r)->latest('id')->paginate(50);
    }
    public function show(Request $r, int $id)
    {
        $ticket = $this->scope($r)->where('id', $id)->first();
        abort_unless($ticket, 404);
        $messages = $this->db
            ->table('support_messages')
            ->where('ticket_id', $id)
            ->when(!$r->user()->isSystem(), fn($q) => $q->where('internal', false))
            ->orderBy('id')
            ->get();
        $names = $this->accounts->names($messages->pluck('user_id')->all());
        return [
            'ticket' => $ticket,
            'messages' => $messages->map(
                fn($m) => [...(array) $m, 'author' => $names[$m->user_id] ?? 'Gelöschtes Konto'],
            ),
        ];
    }

    public function create(Request $r)
    {
        abort_unless($r->user()->hasPermission('support.access'), 403);
        $data = $r->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'tenant_id' => 'nullable|integer',
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
        ]);
        $tenant = $r->user()->isSystem() ? $data['tenant_id'] ?? null : $r->user()->tenant_id;
        abort_unless($tenant && $this->tenants->exists((int) $tenant), 422, 'Restaurant auswählen.');
        $id = $this->db->transaction(function () use ($r, $data, $tenant) {
            $id = $this->db->table('support_tickets')->insertGetId([
                'tenant_id' => $tenant,
                'user_id' => $r->user()->id,
                'subject' => $data['subject'],
                'priority' => $data['priority'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->db->table('support_messages')->insert([
                'ticket_id' => $id,
                'user_id' => $r->user()->id,
                'body' => $data['body'],
                'internal' => false,
                'created_at' => now(),
            ]);
            $this->audit->record('support.created', $id, $tenant);
            return $id;
        });
        return response()->json(['id' => $id], 201);
    }
    public function reply(Request $r, int $id)
    {
        $ticket = $this->scope($r)->where('id', $id)->first();
        abort_unless($ticket, 404);
        $data = $r->validate([
            'body' => 'required|string|max:10000',
            'internal' => 'sometimes|boolean',
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'closed'])],
        ]);
        abort_if(($data['internal'] ?? false) && !$r->user()->isSystem(), 403);
        $this->db->transaction(function () use ($r, $id, $data) {
            $this->db->table('support_messages')->insert([
                'ticket_id' => $id,
                'user_id' => $r->user()->id,
                'body' => $data['body'],
                'internal' => $data['internal'] ?? false,
                'created_at' => now(),
            ]);
            $this->db
                ->table('support_tickets')
                ->where('id', $id)
                ->update(['status' => $data['status'] ?? 'open', 'updated_at' => now()]);
        });
        $this->audit->record('support.replied', $id, $ticket->tenant_id);
        return $this->show($r, $id);
    }
}
