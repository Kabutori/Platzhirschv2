<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\Audit;
class SupportController
{
    private function scope(Request $r)
    {
        abort_unless($r->user()->hasPermission('support.access'), 403);
        return DB::table('support_tickets')->when(
            !$r->user()->isSystem(),
            fn($q) => $q->where('tenant_id', $r->user()->tenant_id),
        );
    }
    public function index(Request $r)
    {
        return $this->scope($r)->latest('id')->paginate(50);
    }
    public function show(Request $r, int $id)
    {
        $ticket = $this->scope($r)->where('id', $id)->first();
        abort_unless($ticket, 404);
        return [
            'ticket' => $ticket,
            'messages' => DB::table('support_messages')
                ->join('users', 'users.id', '=', 'support_messages.user_id')
                ->where('ticket_id', $id)
                ->when(!$r->user()->isSystem(), fn($q) => $q->where('internal', false))
                ->select('support_messages.*', 'users.name as author')
                ->orderBy('support_messages.id')
                ->get(),
        ];
    }
    public function create(Request $r)
    {
        abort_unless($r->user()->hasPermission('support.access'), 403);
        $data = $r->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'tenant_id' => 'nullable|exists:tenants,id',
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
        ]);
        $tenant = $r->user()->isSystem() ? $data['tenant_id'] ?? null : $r->user()->tenant_id;
        abort_unless($tenant, 422, 'Restaurant auswählen.');
        $id = DB::transaction(function () use ($r, $data, $tenant) {
            $id = DB::table('support_tickets')->insertGetId([
                'tenant_id' => $tenant,
                'user_id' => $r->user()->id,
                'subject' => $data['subject'],
                'priority' => $data['priority'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('support_messages')->insert([
                'ticket_id' => $id,
                'user_id' => $r->user()->id,
                'body' => $data['body'],
                'internal' => false,
                'created_at' => now(),
            ]);
            Audit::record('support.created', $id, $tenant);
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
        DB::transaction(function () use ($r, $id, $data) {
            DB::table('support_messages')->insert([
                'ticket_id' => $id,
                'user_id' => $r->user()->id,
                'body' => $data['body'],
                'internal' => $data['internal'] ?? false,
                'created_at' => now(),
            ]);
            DB::table('support_tickets')
                ->where('id', $id)
                ->update(['status' => $data['status'] ?? 'open', 'updated_at' => now()]);
        });
        Audit::record('support.replied', $id, $ticket->tenant_id);
        return $this->show($r, $id);
    }
}
