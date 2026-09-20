<?php
namespace App\Modules\Support\Http;
use Illuminate\Http\Request;
use App\Core\Export\ExportJobs;
class ExportController
{
    public function __construct(private ExportJobs $exports) {}
    public function direct(Request $r)
    {
        return $this->exports->direct($r, 'support-tickets');
    }
    public function start(Request $r)
    {
        return response()->json($this->exports->start($r, 'support-tickets'), 202);
    }
    public function status(Request $r, string $id)
    {
        return $this->exports->status($r, 'support-tickets', $id);
    }
    public function download(Request $r, string $id)
    {
        return $this->exports->download($r, 'support-tickets', $id);
    }
}
