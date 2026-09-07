<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function availableTables(string $date, int $partySize, int $duration, string $timezone)
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $date, $timezone);
        $end = $start->addMinutes($duration);
        if ($start->lessThan(CarbonImmutable::now($timezone))) {
            $this->invalid('starts_at', 'Bitte einen zukünftigen Termin wählen.');
        }
        $this->assertOpeningHours($start, $end);
        // Availability is advisory. The final booking still takes transaction locks.
        return DB::connection('tenant')
            ->table('dining_tables')
            ->where('active', true)
            ->where('capacity', '>=', $partySize)
            ->whereNotExists(function ($query) use ($start, $end) {
                $query
                    ->selectRaw('1')
                    ->from('reservations')
                    ->whereColumn('reservations.table_id', 'dining_tables.id')
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
        $db = DB::connection('tenant');
        return $db->transaction(function () use ($db, $data, $timezone, $id, $source) {
            $old = $id ? $db->table('reservations')->where('id', $id)->first() : null;
            abort_if($id && !$old, 404);
            // All writes lock tables in ascending order, including moves. This serializes overlap checks.
            $tableIds = array_unique(array_filter([$data['table_id'], $old?->table_id]));
            sort($tableIds);
            $tables = $db
                ->table('dining_tables')
                ->whereIn('id', $tableIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $table = $tables->firstWhere('id', (int) $data['table_id']);
            abort_unless($table && $table->active, 422, 'Tisch ist nicht verfügbar.');
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
            if ($data['party_size'] > $table->capacity) {
                $this->invalid('party_size', 'Zu viele Gäste für diesen Tisch.');
            }
            if ($start->lessThan(CarbonImmutable::now($timezone)->subMinutes(5)) && !$id) {
                $this->invalid('starts_at', 'Bitte einen zukünftigen Termin wählen.');
            }
            if ($status !== 'cancelled') {
                $this->assertOpeningHours($start, $end);
                $conflict = $db
                    ->table('reservations')
                    ->where('table_id', $table->id)
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
                    ->update([...$values, 'version' => DB::raw('version + 1')]);
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
            return $db->table('reservations')->find($id);
        }, 3);
    }
    private function assertOpeningHours(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $db = DB::connection('tenant');
        $special = $db->table('special_days')->where('date', $start->toDateString())->first();
        if ($special?->closed) {
            $this->invalid('starts_at', 'An diesem Tag ist geschlossen.');
        }
        $slots = $special
            ? collect([$special])
            : $db->table('opening_hours')->where('weekday', $start->dayOfWeekIso)->get();
        foreach ($slots as $slot) {
            if (
                $start->isSameDay($end) &&
                $start->format('H:i:s') >= $slot->opens &&
                $end->format('H:i:s') <= $slot->closes
            ) {
                return;
            }
        }
        $this->invalid('starts_at', 'Der gesamte Termin muss innerhalb einer Öffnungszeit liegen.');
    }
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
