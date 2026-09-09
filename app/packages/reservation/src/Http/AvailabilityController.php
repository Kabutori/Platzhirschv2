<?php
namespace App\Modules\Reservation\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use App\Contracts\Module\AuditSink;
use Carbon\CarbonImmutable;
class AvailabilityController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
    public function index(Request $r, string $kind)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $db = $this->db->connection('tenant');
        if ($kind === 'room-closures') {
            return $db->table('room_closures')->orderByDesc('starts_at')->get();
        }
        return $db
            ->table('table_combinations')
            ->orderBy('name')
            ->get()
            ->map(function ($row) use ($db) {
                $row->table_ids = $db
                    ->table('table_combination_members')
                    ->where('combination_id', $row->id)
                    ->pluck('table_id')
                    ->all();
                return $row;
            });
    }
    public function save(Request $r, string $kind, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $db = $this->db->connection('tenant');
        $data = $r->validate(
            $kind === 'room-closures'
                ? [
                    'room_id' => 'required|integer|exists:tenant.rooms,id',
                    'starts_at' => 'required|date_format:Y-m-d\\TH:i',
                    'ends_at' => 'required|date_format:Y-m-d\\TH:i|after:starts_at',
                    'reason' => 'required|string|max:250',
                ]
                : [
                    'name' => 'required|string|max:120',
                    'active' => 'required|boolean',
                    'table_ids' => 'required|array|min:2|max:10',
                    'table_ids.*' => 'required|integer|distinct|exists:tenant.dining_tables,id',
                ],
        );
        return $db->transaction(function () use ($r, $kind, $id, $db, $data) {
            if ($kind === 'table-combinations') {
                if ($id) {
                    abort_unless(
                        $db->table('table_combinations')->where('id', $id)->lockForUpdate()->first(),
                        404,
                    );
                }
                $tables = $db
                    ->table('dining_tables')
                    ->whereIn('id', $data['table_ids'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                abort_unless(
                    $tables->count() === count($data['table_ids']) &&
                        $tables->pluck('room_id')->unique()->count() === 1 &&
                        $tables->every(fn($t) => $t->active),
                    422,
                    'Nur aktive Tische desselben Raums kombinieren.',
                );
                $values = ['name' => $data['name'], 'active' => $data['active'], 'updated_at' => now()];
                if ($id) {
                    $db->table('table_combinations')->where('id', $id)->update($values);
                } else {
                    $id = $db->table('table_combinations')->insertGetId([...$values, 'created_at' => now()]);
                }
                $db->table('table_combination_members')->where('combination_id', $id)->delete();
                foreach ($data['table_ids'] as $table) {
                    $db->table('table_combination_members')->insert([
                        'combination_id' => $id,
                        'table_id' => $table,
                    ]);
                }
            } else {
                $tables = $db
                    ->table('dining_tables')
                    ->where('room_id', $data['room_id'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');
                $tz = $r->attributes->get('tenant')->timezone;
                foreach (['starts_at', 'ends_at'] as $key) {
                    $local = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $data[$key], $tz);
                    abort_unless(
                        $local->format('Y-m-d\\TH:i') === $data[$key],
                        422,
                        'Uhrzeit existiert nicht.',
                    );
                    foreach ([-60, -30, 30, 60] as $offset) {
                        abort_if(
                            $local->utc()->addMinutes($offset)->setTimezone($tz)->format('Y-m-d\\TH:i') ===
                                $data[$key],
                            422,
                            'Uhrzeit ist wegen Zeitumstellung doppeldeutig.',
                        );
                    }
                    $data[$key] = $local->utc()->format('Y-m-d H:i:s');
                }
                abort_if(
                    $db
                        ->table('reservations')
                        ->whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('starts_at', '<', $data['ends_at'])
                        ->where('ends_at', '>', $data['starts_at'])
                        ->where(function ($q) use ($tables) {
                            $q->whereIn('table_id', $tables)->orWhereExists(
                                fn($e) => $e
                                    ->selectRaw('1')
                                    ->from('reservation_extra_tables')
                                    ->whereColumn('reservation_id', 'reservations.id')
                                    ->whereIn('reservation_extra_tables.table_id', $tables),
                            );
                        })
                        ->exists(),
                    409,
                    'Im Sperrzeitraum bestehen Reservierungen. Bitte zuerst umbuchen oder stornieren.',
                );
                if ($id) {
                    abort_unless($db->table('room_closures')->where('id', $id)->exists(), 404);
                    $db->table('room_closures')
                        ->where('id', $id)
                        ->update([...$data, 'updated_at' => now()]);
                } else {
                    $id = $db
                        ->table('room_closures')
                        ->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit->record('restaurant.' . $kind . '.saved', $id, $r->attributes->get('tenant')->id);
            return ['id' => $id];
        }, 3);
    }
    public function delete(Request $r, string $kind, int $id)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(
            $this->db
                ->connection('tenant')
                ->table($kind === 'room-closures' ? 'room_closures' : 'table_combinations')
                ->where('id', $id)
                ->delete(),
            404,
        );
        $this->audit->record('restaurant.' . $kind . '.deleted', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
}
