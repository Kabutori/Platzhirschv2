<?php
namespace App\Modules\Reservation\Http;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Carbon\CarbonImmutable;
use App\Contracts\Module\AuditSink;
use App\Modules\Reservation\PublicApi\ReservationGateway;
class WaitlistController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
    private function authorize(Request $r, bool $write = false): void
    {
        abort_unless($r->user()->hasPermission('waitlist.read'), 403);
        if ($write) {
            abort_unless($r->user()->hasPermission('waitlist.write'), 403);
        }
    }
    public function index(Request $r)
    {
        $this->authorize($r);
        $r->validate(['date' => 'required|date_format:Y-m-d']);
        $day = CarbonImmutable::parse(
            $r->input('date'),
            $r->attributes->get('tenant')->timezone,
        )->startOfDay();
        return $this->db
            ->connection('tenant')
            ->table('reservation_waitlist')
            ->where('requested_at', '>=', $day->utc())
            ->where('requested_at', '<', $day->addDay()->utc())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
    public function save(Request $r, ?int $id = null)
    {
        $this->authorize($r, true);
        $data = $r->validate([
            'guest_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:254',
            'phone' => 'nullable|string|max:50',
            'party_size' => 'required|integer|min:1|max:50',
            'requested_at' => 'required|date_format:Y-m-d\TH:i',
            'duration_minutes' => 'required|integer|min:15|max:360',
            'notes' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:waiting,contacted,cancelled',
            'version' => $id ? 'required|integer|min:1' : 'sometimes|integer',
            'request_key' => $id ? 'sometimes|uuid' : 'required|uuid',
        ]);
        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $data['requested_at'],
            $r->attributes->get('tenant')->timezone,
        );
        abort_if(
            !$id && $date->lessThan(now()->subMinutes(5)),
            422,
            'Bitte einen zukünftigen Termin wählen.',
        );
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $id, $data, $date, $db) {
            if ($id) {
                $old = $db->table('reservation_waitlist')->where('id', $id)->lockForUpdate()->first();
                abort_unless($old, 404);
                abort_if(
                    $old->status === 'booked' || (int) $old->version !== (int) $data['version'],
                    409,
                    'Eintrag wurde bereits übernommen oder geändert. Bitte neu laden.',
                );
            } else {
                $old = $db
                    ->table('reservation_waitlist')
                    ->where('request_key', $data['request_key'])
                    ->first();
                if ($old) {
                    return response()->json($old, 200);
                }
            }
            $values = [
                ...$data,
                'requested_at' => $date->utc()->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ];
            unset($values['version'], $values['request_key']);
            if ($id) {
                $db->table('reservation_waitlist')
                    ->where('id', $id)
                    ->update([...$values, 'version' => $db->raw('version + 1')]);
            } else {
                $id = $db
                    ->table('reservation_waitlist')
                    ->insertGetId([...$values, 'request_key' => $data['request_key'], 'created_at' => now()]);
            }
            $this->audit->record('waitlist.saved', $id, $r->attributes->get('tenant')->id);
            return response()->json($db->table('reservation_waitlist')->find($id));
        });
    }
    public function book(Request $r, int $id, ReservationGateway $service)
    {
        $this->authorize($r, true);
        abort_unless(
            $r->user()->hasPermission('reservation.write') && $r->user()->hasPermission('reservation.read'),
            403,
        );
        $data = $r->validate([
            'version' => 'required|integer|min:1',
            'table_id' => 'required|integer',
            'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'duration_minutes' => 'required|integer|min:15|max:360',
        ]);
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $id, $data, $db, $service) {
            $entry = $db->table('reservation_waitlist')->where('id', $id)->lockForUpdate()->first();
            abort_unless($entry, 404);
            if ($entry->status === 'booked') {
                return response()->json(['reservation_id' => $entry->reservation_id]);
            }
            abort_if(
                !in_array($entry->status, ['waiting', 'contacted'], true) ||
                    (int) $entry->version !== (int) $data['version'],
                409,
                'Eintrag wurde geändert oder storniert. Bitte neu laden.',
            );
            $booking = $service->save(
                [
                    ...$data,
                    'guest_name' => $entry->guest_name,
                    'email' => $entry->email,
                    'phone' => $entry->phone,
                    'party_size' => $entry->party_size,
                    'notes' => $entry->notes,
                    'request_key' => $entry->request_key,
                ],
                $r->attributes->get('tenant')->timezone,
                null,
                'waitlist',
            );
            $db->table('reservation_waitlist')
                ->where('id', $id)
                ->update([
                    'status' => 'booked',
                    'reservation_id' => $booking->id,
                    'version' => $db->raw('version + 1'),
                    'updated_at' => now(),
                ]);
            $this->audit->record('waitlist.booked', $id, $r->attributes->get('tenant')->id);
            return response()->json(['reservation_id' => $booking->id]);
        });
    }
}
