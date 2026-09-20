<?php
namespace App\Modules\Customer\Http;
use Illuminate\Http\Request;
use App\Core\Export\ExportJobs;
class ExportController
{
    public function __construct(private ExportJobs $exports) {}
    public function direct(Request $r)
    {
        return $this->exports->direct($r, 'customers');
    }
    public function start(Request $r)
    {
        return response()->json($this->exports->start($r, 'customers'), 202);
    }
    public function status(Request $r, string $id)
    {
        return $this->exports->status($r, 'customers', $id);
    }
    public function download(Request $r, string $id)
    {
        return $this->exports->download($r, 'customers', $id);
    }
}
