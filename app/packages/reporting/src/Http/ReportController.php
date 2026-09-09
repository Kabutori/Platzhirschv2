<?php
namespace App\Modules\Reporting\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use App\Contracts\Module\ReservationReports;
use App\Modules\Billing\PublicApi\Entitlements;
use Carbon\Carbon;
class ReportController
{
    public function __construct(
        private DatabaseManager $db,
        private ReservationReports $reports,
        private Entitlements $entitlements,
    ) {}
    private function authorize(Request $r, string $permission): void
    {
        abort_unless($r->user()->hasPermission($permission), 403);
        abort_unless(
            $this->entitlements->active($r->attributes->get('tenant')->id, 'reporting'),
            403,
            'Reporting ist nicht aktiv oder der Nutzungszeitraum ist abgelaufen.',
        );
    }
    private function range(Request $r): array
    {
        $d = $r->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        abort_if(
            Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 92,
            422,
            'Höchstens 93 Tage auswählen.',
        );
        return $d;
    }
    public function index(Request $r): array
    {
        $this->authorize($r, 'reporting.read');
        $d = $this->range($r);
        return [
            'days' => $this->reports->daily($d['from'], $d['to']),
            'saved' => $this->db
                ->connection('tenant')
                ->table('reporting_saved_reports')
                ->orderBy('name')
                ->get(),
        ];
    }
    public function save(Request $r)
    {
        $this->authorize($r, 'reporting.manage');
        $d = $this->range($r);
        $name = $r->validate(['name' => 'required|string|max:120'])['name'];
        $id = $this->db
            ->connection('tenant')
            ->table('reporting_saved_reports')
            ->insertGetId([
                'name' => $name,
                'date_from' => $d['from'],
                'date_to' => $d['to'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        return response()->json(['id' => $id], 201);
    }
    public function delete(Request $r, int $id)
    {
        $this->authorize($r, 'reporting.manage');
        abort_unless(
            $this->db->connection('tenant')->table('reporting_saved_reports')->where('id', $id)->delete(),
            404,
        );
        return response()->noContent();
    }
}
