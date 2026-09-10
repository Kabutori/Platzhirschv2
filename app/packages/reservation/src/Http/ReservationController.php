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
    public function __construct(
        private DatabaseManager $db,
        private AuditSink $audit,
        private ?\App\Contracts\Module\WeatherForecast $weather = null,
    ) {}
    public function weather(Request $r): array
    {
        abort_unless(
            $r->user()->hasPermission('reservation.read') ||
                $r->user()->hasPermission('restaurant.configure'),
            403,
        );
        $tenant = $r->attributes->get('tenant');
        return $this->weather?->forecast($tenant->id, $tenant->timezone ?? 'Europe/Berlin') ?? [
            'status' => 'inactive',
            'days' => [],
        ];
    }
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
    public function assignTables(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $v = $r->validate([
            'tables' => ['required', 'array', 'min:1', 'max:100'],
            'tables.*' => ['array:id,room_id'],
            'tables.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'tables.*.room_id' => ['required', 'integer', 'min:1'],
        ]);
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $id, $v, $db) {
            abort_unless($db->table('rooms')->where('id', $id)->lockForUpdate()->first(), 404);
            $before = collect($v['tables'])->pluck('room_id', 'id');
            $tables = $db
                ->table('dining_tables')
                ->whereIn('id', $before->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless(
                $tables->count() === $before->count(),
                409,
                'Die Tischauswahl wurde inzwischen geändert.',
            );
            foreach ($tables as $table) {
                abort_unless(
                    $table->room_id == $before[$table->id],
                    409,
                    'Eine Raumzuordnung wurde inzwischen geändert. Bitte neu laden.',
                );
            }
            $moving = $tables->filter(fn($table) => $table->room_id != $id)->pluck('id');
            if ($moving->isNotEmpty()) {
                abort_if(
                    $db->table('table_combination_members')->whereIn('table_id', $moving)->exists(),
                    409,
                    'Tische sind in gespeicherten Kombinationen enthalten. Kombinationen vor dem Raumwechsel anpassen.',
                );
                abort_if(
                    $db
                        ->table('reservations')
                        ->whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('ends_at', '>', now())
                        ->where(
                            fn($q) => $q
                                ->whereIn('table_id', $moving)
                                ->orWhereIn(
                                    'id',
                                    $db
                                        ->table('reservation_extra_tables')
                                        ->select('reservation_id')
                                        ->whereIn('table_id', $moving),
                                ),
                        )
                        ->exists(),
                    409,
                    'Tische mit laufenden oder zukünftigen Reservierungen können nicht in einen anderen Raum verschoben werden.',
                );
                $db->table('dining_tables')
                    ->whereIn('id', $moving)
                    ->update(['room_id' => $id, 'updated_at' => now()]);
            }
            $this->audit->record('restaurant.room_tables_assigned', $id, $r->attributes->get('tenant')->id);
            return ['assigned' => $moving->count()];
        });
    }
    public function week(Request $r)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $rows = $this->db->connection('tenant')->table('opening_hours')->orderBy('id')->get();
        return ['rows' => $rows, 'revision' => hash('sha256', $rows->toJson())];
    }
    public function saveWeek(Request $r)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $v = $r->validate([
            'revision' => ['required', 'string', 'size:64'],
            'rows' => ['present', 'array', 'max:42'],
            'rows.*' => ['array:weekday,opens,closes'],
            'rows.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'rows.*.opens' => ['required', 'date_format:H:i'],
            'rows.*.closes' => ['required', 'date_format:H:i'],
        ]);
        $intervals = [];
        foreach ($v['rows'] as $row) {
            abort_if($row['opens'] === $row['closes'], 422, 'Öffnen und Schließen dürfen nicht gleich sein.');
            $minutes = fn($t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
            $start = ($row['weekday'] - 1) * 1440 + $minutes($row['opens']);
            $end = ($row['weekday'] - 1) * 1440 + $minutes($row['closes']);
            if ($end < $start) {
                $end += 1440;
            }
            foreach ($intervals as [$a, $b]) {
                foreach ([-10080, 0, 10080] as $shift) {
                    abort_if(
                        $start < $b + $shift && $end > $a + $shift,
                        422,
                        'Öffnungszeiten überschneiden sich.',
                    );
                }
            }
            $intervals[] = [$start, $end];
        }
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $v, $db) {
            $rows = $db->table('opening_hours')->orderBy('id')->lockForUpdate()->get();
            abort_unless(
                hash_equals(hash('sha256', $rows->toJson()), $v['revision']),
                409,
                'Öffnungszeiten wurden inzwischen geändert. Bitte neu laden.',
            );
            $db->table('opening_hours')->delete();
            foreach ($v['rows'] as $row) {
                $db->table('opening_hours')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('restaurant.hours.week_saved', null, $r->attributes->get('tenant')->id);
            return ['saved' => true];
        });
    }
    public function save(Request $r, string $resource, ?int $id = null)
    {
        if ($resource !== 'hours') {
            return $this->saveResource($r, $resource, $id);
        }
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $resource, $id, $db) {
            $db->table('opening_hours')->orderBy('id')->lockForUpdate()->get();
            return $this->saveResource($r, $resource, $id);
        });
    }
    private function saveResource(Request $r, string $resource, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $rules = match ($resource) {
            'rooms' => [
                'name' => 'required|string|max:120',
                'color' => ['required', Rule::in(['terracotta', 'sage', 'sky', 'mustard', 'plum', 'slate'])],
                'outdoor' => 'required|boolean',
                'weather_dependent' => 'sometimes|boolean',
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
                'closes' => 'required|date_format:H:i|different:opens',
            ],
            'special-days' => [
                'date' => 'required|date_format:Y-m-d',
                'closed' => 'required|boolean',
                'opens' => 'nullable|required_if:closed,false|date_format:H:i',
                'closes' => 'nullable|required_if:closed,false|date_format:H:i|different:opens',
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
        if ($resource !== 'hours') {
            return $this->deleteResource($r, $resource, $id);
        }
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $resource, $id, $db) {
            $db->table('opening_hours')->orderBy('id')->lockForUpdate()->get();
            return $this->deleteResource($r, $resource, $id);
        });
    }
    private function deleteResource(Request $r, string $resource, int $id)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $db = $this->db->connection('tenant');
        abort_if(
            $resource === 'tables' &&
                ($db->table('reservations')->where('table_id', $id)->exists() ||
                    $db->table('reservation_extra_tables')->where('table_id', $id)->exists() ||
                    $db->table('table_combination_members')->where('table_id', $id)->exists()),
            409,
            'Tisch hat Reservierungen oder gehört zu einer Kombination. Bitte deaktivieren oder die Kombination zuerst bearbeiten.',
        );
        abort_if(
            $resource === 'rooms' &&
                ($db->table('dining_tables')->where('room_id', $id)->exists() ||
                    $db->table('room_closures')->where('room_id', $id)->exists()),
            409,
            'Raum enthält noch Tische oder Sperrzeiten.',
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
        $rows = $this->db
            ->connection('tenant')
            ->table('reservations')
            ->join('dining_tables', 'table_id', '=', 'dining_tables.id')
            ->select('reservations.*', 'dining_tables.name as table_name')
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->orderBy('starts_at')
            ->get();
        $extra = $this->db
            ->connection('tenant')
            ->table('reservation_extra_tables')
            ->join('dining_tables', 'table_id', '=', 'dining_tables.id')
            ->whereIn('reservation_id', $rows->pluck('id'))
            ->get(['reservation_id', 'table_id', 'name'])
            ->groupBy('reservation_id');
        foreach ($rows as $row) {
            $members = $extra->get($row->id, collect());
            $row->additional_table_ids = $members->pluck('table_id')->all();
            if ($members->isNotEmpty()) {
                $row->table_name .= ' + ' . $members->pluck('name')->join(' + ');
            }
        }
        return $rows;
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
            $ids = [
                $reservation->table_id,
                ...$db
                    ->table('reservation_extra_tables')
                    ->where('reservation_id', $id)
                    ->pluck('table_id')
                    ->all(),
            ];
            $db->table('dining_tables')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $db->table('reservations')
                ->where('id', $id)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now()->utc(),
                    'version' => $this->db->raw('version + 1'),
                ]);
            app(\App\Modules\Reservation\Application\ReservationNotifications::class)->enqueue(
                $db->table('reservations')->find($id),
            );
        });
        $this->audit->record('reservation.cancelled', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    public function export(Request $r)
    {
        abort_unless($r->user()->hasPermission('reservation.export'), 403);
        $r->validate(['format' => 'sometimes|in:csv,xlsx,print']);
        $rows = $this->reservations($r);
        $this->audit->record('reservation.exported', $r->input('date'), $r->attributes->get('tenant')->id);
        $format = $r->input('format', 'csv');
        if ($format !== 'csv') {
            $export = app(\App\Modules\Reservation\Application\ReservationExport::class);
            $values = $export->rows($rows, $r->attributes->get('tenant')->timezone);
            if ($format === 'xlsx') {
                return response($export->xlsx($values), 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' =>
                        'attachment; filename="reservierungen-' . $r->input('date') . '.xlsx"',
                    'Cache-Control' => 'no-store',
                ]);
            }
            return response($export->printable($values, $r->input('date')), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
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
