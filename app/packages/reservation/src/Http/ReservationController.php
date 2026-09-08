<?php
namespace App\Modules\Reservation\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\CarbonImmutable;
use App\Contracts\Module\AuditSink;
use App\Modules\Reservation\PublicApi\ReservationGateway;
class ReservationController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
    private const RESOURCES = [
        'rooms' => 'rooms',
        'tables' => 'dining_tables',
        'hours' => 'opening_hours',
        'special-days' => 'special_days',
    ];
    public function index(Request $r, string $resource)
    {
        abort_unless(
            $r->user()->hasPermission('reservation.read') ||
                $r->user()->hasPermission('restaurant.configure'),
            403,
        );
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        return $this->db->connection('tenant')->table(self::RESOURCES[$resource])->orderBy('id')->get();
    }
    public function save(Request $r, string $resource, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $rules = match ($resource) {
            'rooms' => [
                'name' => 'required|string|max:120',
                'color' => ['required', Rule::in(['terracotta', 'sage', 'sky', 'mustard', 'plum', 'slate'])],
                'outdoor' => 'required|boolean',
                'location' => 'nullable|string|max:200',
                'note' => 'nullable|string|max:2000',
                'icon' => 'sometimes|in:room,terrace,bar,event',
            ],
            'tables' => [
                'name' => 'required|string|max:60',
                'room_id' => 'required|integer|exists:tenant.rooms,id',
                'capacity' => 'required|integer|min:1|max:50',
                'active' => 'required|boolean',
                'shape' => 'sometimes|in:rectangle,square,round',
                'layout_x' => 'nullable|integer|min:0|max:100',
                'layout_y' => 'nullable|integer|min:0|max:100',
            ],
            'hours' => [
                'weekday' => 'required|integer|min:1|max:7',
                'opens' => 'required|date_format:H:i',
                'closes' => 'required|date_format:H:i|after:opens',
            ],
            'special-days' => [
                'date' => 'required|date_format:Y-m-d',
                'closed' => 'required|boolean',
                'opens' => 'nullable|required_if:closed,false|date_format:H:i',
                'closes' => 'nullable|required_if:closed,false|date_format:H:i|after:opens',
                'note' => 'nullable|string|max:250',
            ],
        };
        $data = $r->validate($rules);
        $db = $this->db->connection('tenant');
        $table = self::RESOURCES[$resource];
        if ($resource === 'special-days') {
            abort_if(
                $db
                    ->table($table)
                    ->where('date', $data['date'])
                    ->when($id, fn($q) => $q->where('id', '!=', $id))
                    ->exists(),
                422,
                'Sondertag bereits vorhanden.',
            );
        }
        if ($id) {
            abort_unless($db->table($table)->where('id', $id)->exists(), 404);
            $db->table($table)
                ->where('id', $id)
                ->update([...$data, 'updated_at' => now()]);
        } else {
            $id = $db->table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit->record('restaurant.' . $resource . '.saved', $id, $r->attributes->get('tenant')->id);
        return $db->table($table)->find($id);
    }
    public function delete(Request $r, string $resource, int $id)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $db = $this->db->connection('tenant');
        abort_if(
            $resource === 'tables' && $db->table('reservations')->where('table_id', $id)->exists(),
            409,
            'Tisch hat Reservierungen. Bitte deaktivieren statt löschen.',
        );
        abort_if(
            $resource === 'rooms' && $db->table('dining_tables')->where('room_id', $id)->exists(),
            409,
            'Raum enthält noch Tische.',
        );
        abort_unless($db->table(self::RESOURCES[$resource])->where('id', $id)->delete(), 404);
        $this->audit->record('restaurant.' . $resource . '.deleted', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    public function reservations(Request $r)
    {
        abort_unless($r->user()->hasPermission('reservation.read'), 403);
        $r->validate(['date' => 'required|date_format:Y-m-d']);
        $tenant = $r->attributes->get('tenant');
        $day = CarbonImmutable::parse($r->input('date'), $tenant->timezone)->startOfDay();
        return $this->db
            ->connection('tenant')
            ->table('reservations')
            ->join('dining_tables', 'table_id', '=', 'dining_tables.id')
            ->select('reservations.*', 'dining_tables.name as table_name')
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->orderBy('starts_at')
            ->get();
    }
    public function saveReservation(Request $r, ReservationGateway $service, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission('reservation.write'), 403);
        $data = $r->validate(\App\Modules\Reservation\PublicApi\ReservationRules::rules());
        abort_if(
            ($data['status'] ?? '') === 'cancelled' && !$r->user()->hasPermission('reservation.cancel'),
            403,
        );
        $tenant = $r->attributes->get('tenant');
        $result = $service->save($data, $tenant->timezone, $id);
        $this->audit->record($id ? 'reservation.updated' : 'reservation.created', $result->id, $tenant->id);
        return response()->json($result, $id ? 200 : 201);
    }
    public function cancel(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('reservation.cancel'), 403);
        $db = $this->db->connection('tenant');
        $db->transaction(function () use ($db, $id) {
            $reservation = $db->table('reservations')->find($id);
            abort_unless($reservation, 404);
            $db->table('dining_tables')->where('id', $reservation->table_id)->lockForUpdate()->first();
            $db->table('reservations')
                ->where('id', $id)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now()->utc(),
                    'version' => $this->db->raw('version + 1'),
                ]);
        });
        $this->audit->record('reservation.cancelled', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    public function export(Request $r)
    {
        abort_unless($r->user()->hasPermission('reservation.export'), 403);
        $rows = $this->reservations($r);
        $this->audit->record('reservation.exported', $r->input('date'), $r->attributes->get('tenant')->id);
        return response()->streamDownload(
            function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv(
                    $out,
                    ['ID', 'Gast', 'Tisch', 'Personen', 'Beginn UTC', 'Ende UTC', 'Status'],
                    ';',
                    '"',
                    '',
                );
                foreach ($rows as $row) {
                    $values = [
                        $row->id,
                        $row->guest_name,
                        $row->table_name,
                        $row->party_size,
                        $row->starts_at,
                        $row->ends_at,
                        $row->status,
                    ];
                    $values = array_map(
                        fn($v) => preg_match('/^[=+@\-\t\r\n]/', (string) $v) ? "'" . $v : $v,
                        $values,
                    );
                    fputcsv($out, $values, ';', '"', '');
                }
                fclose($out);
            },
            'reservierungen.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
