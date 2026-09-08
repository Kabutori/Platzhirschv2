<?php
namespace App\Modules\Reservation\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class ReservationService implements \App\Modules\Reservation\PublicApi\ReservationGateway
{
    public function __construct(private DatabaseManager $db) {}
    public function catalog(): array
    {
        return [
            'tables' => $this->db
                ->connection('tenant')
                ->table('dining_tables')
                ->where('active', true)
                ->get(['id', 'name', 'capacity']),
            'hours' => $this->db
                ->connection('tenant')
                ->table('opening_hours')
                ->get(['weekday', 'opens', 'closes']),
        ];
    }
    public function availableTables(
        string $date,
        int $partySize,
        int $duration,
        string $timezone,
    ): \Illuminate\Support\Collection {
        $start = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $date, $timezone);
        $end = $start->addMinutes($duration);
        if ($start->lessThan(CarbonImmutable::now($timezone))) {
            $this->invalid('starts_at', 'Bitte einen zukünftigen Termin wählen.');
        }
        $this->assertOpeningHours($start, $end);
        // Availability is advisory. The final booking still takes transaction locks.
        return $this->db
            ->connection('tenant')
            ->table('dining_tables')
            ->where('active', true)
            ->where('capacity', '>=', $partySize)
            ->whereNotExists(function ($query) use ($start, $end) {
                $query
                    ->selectRaw('1')
                    ->from('reservations')
                    ->where(function ($match) {
                        $match
                            ->whereColumn('reservations.table_id', 'dining_tables.id')
                            ->orWhereExists(function ($extra) {
                                $extra
                                    ->selectRaw('1')
                                    ->from('reservation_extra_tables')
                                    ->whereColumn('reservation_id', 'reservations.id')
                                    ->whereColumn('reservation_extra_tables.table_id', 'dining_tables.id');
                            });
                    })
                    ->whereNotIn('status', ['cancelled', 'no_show'])
                    ->where('starts_at', '<', $end->utc()->format('Y-m-d H:i:s'))
                    ->where('ends_at', '>', $start->utc()->format('Y-m-d H:i:s'));
            })
            ->orderBy('capacity')
            ->orderBy('id')
            ->get(['id', 'name', 'capacity']);
    }
    public function save(array $data, string $timezone, ?int $id = null, string $source = 'admin'): object
    {
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($db, $data, $timezone, $id, $source) {
            $old = $id ? $db->table('reservations')->where('id', $id)->first() : null;
            abort_if($id && !$old, 404);
            // All writes lock tables in ascending order, including moves. This serializes overlap checks.
            $extraIds = array_map('intval', $data['additional_table_ids'] ?? []);
            $newIds = array_values(array_unique([(int) $data['table_id'], ...$extraIds]));
            $oldExtra = $id
                ? $db
                    ->table('reservation_extra_tables')
                    ->where('reservation_id', $id)
                    ->pluck('table_id')
                    ->all()
                : [];
            $tableIds = array_unique(array_filter([...$newIds, $old?->table_id, ...$oldExtra]));
            sort($tableIds);
            $tables = $db
                ->table('dining_tables')
                ->whereIn('id', $tableIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $table = $tables->firstWhere('id', (int) $data['table_id']);
            abort_unless($table && $table->active, 422, 'Tisch ist nicht verfügbar.');
            $chosen = $tables->whereIn('id', $newIds);
            abort_unless(
                $chosen->count() === count($newIds) &&
                    $chosen->every(fn($t) => $t->active && $t->room_id === $table->room_id),
                422,
                'Kombinierte Tische müssen aktiv sein und im selben Raum liegen.',
            );
            if ($id) {
                $current = $db->table('reservations')->where('id', $id)->lockForUpdate()->first();
                abort_if(
                    (int) $current->version !== (int) ($data['version'] ?? 0) ||
                        $current->table_id !== $old->table_id,
                    409,
                    'Reservierung wurde gleichzeitig geändert. Bitte neu laden.',
                );
            }
            if (!$id && !empty($data['request_key'])) {
                $existing = $db
                    ->table('reservations')
                    ->where('request_key', $data['request_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], $timezone);
            $end = $start->addMinutes($data['duration_minutes']);
            $status = $data['status'] ?? 'confirmed';
            if ($data['party_size'] > $chosen->sum('capacity')) {
                $this->invalid('party_size', 'Zu viele Gäste für diesen Tisch.');
            }
            if ($start->lessThan(CarbonImmutable::now($timezone)->subMinutes(5)) && !$id) {
                $this->invalid('starts_at', 'Bitte einen zukünftigen Termin wählen.');
            }
            if ($status !== 'cancelled') {
                $this->assertOpeningHours($start, $end);
                $conflict = $db
                    ->table('reservations')
                    ->where(function ($match) use ($newIds) {
                        $match->whereIn('table_id', $newIds)->orWhereExists(function ($extra) use ($newIds) {
                            $extra
                                ->selectRaw('1')
                                ->from('reservation_extra_tables')
                                ->whereColumn('reservation_id', 'reservations.id')
                                ->whereIn('reservation_extra_tables.table_id', $newIds);
                        });
                    })
                    ->whereNotIn('status', ['cancelled', 'no_show'])
                    ->when($id, fn($q) => $q->where('id', '!=', $id))
                    ->where('starts_at', '<', $end->utc()->format('Y-m-d H:i:s'))
                    ->where('ends_at', '>', $start->utc()->format('Y-m-d H:i:s'))
                    ->lockForUpdate()
                    ->first();
                if ($conflict) {
                    abort(409, 'Dieser Tisch ist in dem Zeitraum bereits reserviert.');
                }
            }
            $values = [
                'table_id' => $table->id,
                'guest_name' => $data['guest_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'party_size' => $data['party_size'],
                'starts_at' => $start->utc()->format('Y-m-d H:i:s'),
                'ends_at' => $end->utc()->format('Y-m-d H:i:s'),
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now()->utc(),
            ];
            if ($id) {
                $db->table('reservations')
                    ->where('id', $id)
                    ->update([...$values, 'version' => $this->db->raw('version + 1')]);
            } else {
                $id = $db
                    ->table('reservations')
                    ->insertGetId([
                        ...$values,
                        'source' => $source,
                        'created_at' => now()->utc(),
                        'request_key' => $data['request_key'] ?? null,
                    ]);
            }
            $db->table('reservation_extra_tables')->where('reservation_id', $id)->delete();
            foreach ($newIds as $tableId) {
                if ($tableId !== (int) $table->id) {
                    $db->table('reservation_extra_tables')->insert([
                        'reservation_id' => $id,
                        'table_id' => $tableId,
                    ]);
                }
            }
            $result = $db->table('reservations')->find($id);
            $result->additional_table_ids = array_values(array_diff($newIds, [(int) $table->id]));
            return $result;
        }, 3);
    }
    private function assertOpeningHours(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $db = $this->db->connection('tenant');
        // A special day overrides the entire calendar day, including a spillover
        // from yesterday. Otherwise an overnight service belongs to its opening day.
        foreach ([$start->startOfDay(), $start->startOfDay()->subDay()] as $day) {
            $special = $db->table('special_days')->where('date', $day->toDateString())->first();
            if ($special?->closed) {
                continue;
            }
            $slots = $special
                ? collect([$special])
                : $db->table('opening_hours')->where('weekday', $day->dayOfWeekIso)->get();
            foreach ($slots as $slot) {
                if (!$slot->opens || !$slot->closes || $slot->opens === $slot->closes) {
                    continue;
                }
                $opening = $day->setTimeFromTimeString($slot->opens);
                $closing = $day->setTimeFromTimeString($slot->closes);
                if ($closing->lessThan($opening)) {
                    $closing = $closing->addDay();
                }
                if ($start->lessThan($opening) || $end->greaterThan($closing)) {
                    continue;
                }
                $overridden = false;
                for ($cursor = $day->addDay(); $cursor->lessThan($end); $cursor = $cursor->addDay()) {
                    if ($db->table('special_days')->where('date', $cursor->toDateString())->exists()) {
                        $overridden = true;
                        break;
                    }
                }
                if (!$overridden) {
                    return;
                }
            }
        }
        $this->invalid('starts_at', 'Der gesamte Termin muss innerhalb einer Öffnungszeit liegen.');
    }
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
