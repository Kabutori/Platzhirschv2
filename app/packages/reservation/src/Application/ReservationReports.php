<?php
namespace App\Modules\Reservation\Application;
use App\Contracts\Module\ReservationReports as ReportContract;
use Illuminate\Database\DatabaseManager;
use Carbon\Carbon;
class ReservationReports implements ReportContract
{
    public function __construct(private DatabaseManager $db) {}
    public function daily(string $from, string $to): array
    {
        $timezone = request()->attributes->get('tenant')->timezone;
        $start = Carbon::parse($from, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($to, $timezone)->addDay()->startOfDay()->utc();
        $days = [];
        foreach (
            $this->db
                ->connection('tenant')
                ->table('reservations')
                ->where('starts_at', '>=', $start)
                ->where('starts_at', '<', $end)
                ->orderBy('id')
                ->cursor()
            as $row
        ) {
            $day = Carbon::parse($row->starts_at, 'UTC')->setTimezone($timezone)->format('Y-m-d');
            if (!isset($days[$day])) {
                $days[$day] = ['date' => $day, 'reservations' => 0, 'guests' => 0, 'cancelled' => 0];
            }
            if ($row->status === 'cancelled') {
                $days[$day]['cancelled']++;
            } else {
                $days[$day]['reservations']++;
                $days[$day]['guests'] += (int) $row->party_size;
            }
        }
        ksort($days);
        return array_values($days);
    }
}
