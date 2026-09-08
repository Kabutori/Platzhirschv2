<?php
namespace App\Http\Controllers;
use App\Models\{Tenant, User};
use App\Jobs\ProvisionTenant;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Password, Hash};
use Illuminate\Validation\Rule;

class PlatformController
{
    public function dashboard(Request $r)
    {
        return [
            'tenants_total' => Tenant::count(),
            'tenants_active' => Tenant::where('status', 'active')->count(),
            'tenants_pending' => Tenant::where('status', 'provisioning')->count(),
            'users_total' => User::count(),
            'open_tickets' => DB::table('support_tickets')->where('status', '!=', 'closed')->count(),
            'recent_audit' => $r->user()->hasPermission('platform.audit.read')
                ? DB::table('audit_entries')->latest('id')->limit(10)->get()
                : [],
        ];
    }
    public function audit()
    {
        return DB::table('audit_entries')->latest('id')->paginate(100);
    }
    public function health()
    {
        $start = microtime(true);
        DB::select('SELECT 1');
        return [
            'version' => config('platzhirsch.version'),
            'php' => PHP_VERSION,
            'database' => 'MySQL',
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            'queued_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'mail_configured' => config('mail.default') !== 'log',
            'scheduler_last_seen' => cache()->get('scheduler_last_seen'),
        ];
    }
}
