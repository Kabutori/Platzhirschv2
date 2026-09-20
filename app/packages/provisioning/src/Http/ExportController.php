<?php
namespace App\Modules\Provisioning\Http;
use Illuminate\Http\Request;
use App\Core\Export\ExportJobs;
class ExportController
{
    public function __construct(private ExportJobs $exports) {}
    public function direct(Request $r)
    {
        return $this->exports->direct($r, 'database-servers');
    }
    public function start(Request $r)
    {
        return response()->json($this->exports->start($r, 'database-servers'), 202);
    }
    public function status(Request $r, string $id)
    {
        return $this->exports->status($r, 'database-servers', $id);
    }
    public function download(Request $r, string $id)
    {
        return $this->exports->download($r, 'database-servers', $id);
    }
}
