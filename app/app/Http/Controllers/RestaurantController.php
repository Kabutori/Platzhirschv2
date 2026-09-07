<?php
namespace App\Http\Controllers;
use App\Services\{ReservationService, Audit};
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RestaurantController
{
    private const RESOURCES = [
        'rooms' => 'rooms',
        'tables' => 'dining_tables',
        'hours' => 'opening_hours',
        'special-days' => 'special_days',
    ];
    public function profile(Request $r)
    {
        return $r->attributes->get('tenant');
    }
    public function updateProfile(Request $r)
    {
        abort_unless($r->user()->canManage(), 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:2000',
        ]);
        $tenant = $r->attributes->get('tenant');
        $tenant->update($data);
        Audit::record('restaurant.profile_updated', $tenant->id, $tenant->id);
        return $tenant;
    }
    public function index(Request $r, string $resource)
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        return DB::connection('tenant')->table(self::RESOURCES[$resource])->orderBy('id')->get();
    }
    public function save(Request $r, string $resource, ?int $id = null)
    {
        abort_unless($r->user()->canManage(), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $rules = match ($resource) {
            'rooms' => [
                'name' => 'required|string|max:120',
                'color' => ['required', Rule::in(['terracotta', 'sage', 'sky', 'mustard', 'plum', 'slate'])],
                'outdoor' => 'required|boolean',
            ],
            'tables' => [
                'name' => 'required|string|max:60',
                'room_id' => 'required|integer|exists:tenant.rooms,id',
                'capacity' => 'required|integer|min:1|max:50',
                'active' => 'required|boolean',
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
        $db = DB::connection('tenant');
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
        Audit::record('restaurant.' . $resource . '.saved', $id, $r->attributes->get('tenant')->id);
        return $db->table($table)->find($id);
    }
    public function delete(Request $r, string $resource, int $id)
    {
        abort_unless($r->user()->canManage(), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $db = DB::connection('tenant');
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
        Audit::record('restaurant.' . $resource . '.deleted', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    public function reservations(Request $r)
    {
        $r->validate(['date' => 'required|date_format:Y-m-d']);
        $tenant = $r->attributes->get('tenant');
        $day = CarbonImmutable::parse($r->input('date'), $tenant->timezone)->startOfDay();
        return DB::connection('tenant')
            ->table('reservations')
            ->join('dining_tables', 'table_id', '=', 'dining_tables.id')
            ->select('reservations.*', 'dining_tables.name as table_name')
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->orderBy('starts_at')
            ->get();
    }
    public function saveReservation(Request $r, ReservationService $service, ?int $id = null)
    {
        $data = $r->validate(self::reservationRules());
        $tenant = $r->attributes->get('tenant');
        $result = $service->save($data, $tenant->timezone, $id);
        Audit::record($id ? 'reservation.updated' : 'reservation.created', $result->id, $tenant->id);
        return response()->json($result, $id ? 200 : 201);
    }
    public static function reservationRules(): array
    {
        return [
            'table_id' => 'required|integer',
            'guest_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:254',
            'phone' => 'nullable|string|max:50',
            'party_size' => 'required|integer|min:1|max:50',
            'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'duration_minutes' => 'required|integer|min:15|max:360',
            'status' => ['sometimes', Rule::in(['confirmed', 'seated', 'completed', 'cancelled', 'no_show'])],
            'notes' => 'nullable|string|max:2000',
            'request_key' => 'nullable|uuid',
            'version' => 'nullable|integer|min:1',
        ];
    }
    public function cancel(Request $r, int $id)
    {
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $id) {
            $reservation = $db->table('reservations')->find($id);
            abort_unless($reservation, 404);
            $db->table('dining_tables')->where('id', $reservation->table_id)->lockForUpdate()->first();
            $db->table('reservations')
                ->where('id', $id)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now()->utc(),
                    'version' => DB::raw('version + 1'),
                ]);
        });
        Audit::record('reservation.cancelled', $id, $r->attributes->get('tenant')->id);
        return response()->noContent();
    }
    public function team(Request $r)
    {
        abort_unless($r->user()->canManage(), 403);
        return User::where('tenant_id', $r->attributes->get('tenant')->id)
            ->orderBy('name')
            ->get();
    }
    public function createTeam(Request $r)
    {
        abort_unless($r->user()->canManage(), 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users,email',
            'password' => 'required|string|min:12|max:128',
            'role' => ['required', Rule::in(['restaurant_admin', 'staff'])],
        ]);
        $user = User::create([
            ...$data,
            'email' => strtolower($data['email']),
            'tenant_id' => $r->attributes->get('tenant')->id,
        ]);
        Audit::record('team.created', $user->id, $user->tenant_id);
        return response()->json($user, 201);
    }
    public function export(Request $r)
    {
        $rows = $this->reservations($r);
        Audit::record('reservation.exported', $r->input('date'), $r->attributes->get('tenant')->id);
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
