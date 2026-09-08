<?php
namespace App\Http\Controllers;
use App\Models\{Tenant, User};
use App\Services\{Audit, Totp};
use App\Jobs\MoveTenant;
use App\Modules\Provisioning\PublicApi\ServerDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
class TenantOperationController
{
    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        return DB::table('tenant_operations')
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'tenant_id',
                'kind',
                'status',
                'target_server_id',
                'module_code',
                'error_code',
                'created_at',
                'updated_at',
            ]);
    }
    public function move(Request $r, Tenant $tenant)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        $data = $r->validate([
            'target_server_id' => 'nullable|integer',
            'placement_version' => 'required|integer',
            'password' => 'required|string',
            'code' => 'required|digits:6',
            'backup_confirmed' => 'accepted',
            'downtime_confirmed' => 'accepted',
        ]);
        abort_unless(
            Hash::check($data['password'], $r->user()->password),
            422,
            'Kennwort ist nicht korrekt.',
        );
        $target = $data['target_server_id'] ?? null;
        if ($target) {
            abort_unless(
                app(ServerDirectory::class)->isEnabled($target),
                422,
                'Zielserver ist nicht freigegeben.',
            );
        }
        return DB::transaction(function () use ($r, $tenant, $data, $target) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->mfa_secret, 422, 'Für Umzüge zuerst Zwei-Faktor-Anmeldung einrichten.');
            $step = Totp::verify($user->mfa_secret, $data['code'], $user->mfa_last_step);
            abort_if($step === false, 422, 'Zwei-Faktor-Code ungültig oder bereits verwendet.');
            $user->update(['mfa_last_step' => $step]);
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $tenant->status === 'active' && $tenant->placement_version == $data['placement_version'],
                409,
                'Restaurantstatus oder Zuordnung wurde geändert.',
            );
            abort_if(
                $tenant->server_id == $target,
                422,
                'Restaurant befindet sich bereits auf diesem Server.',
            );
            $tenant->update(['status' => 'moving']);
            $id = DB::table('tenant_operations')->insertGetId([
                'tenant_id' => $tenant->id,
                'kind' => 'move',
                'status' => 'queued',
                'target_server_id' => $target,
                'expected_version' => $tenant->placement_version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            MoveTenant::dispatch($id);
            Audit::record('tenant.move_requested', $id, $tenant->id);
            return response()->json(['operation_id' => $id, 'status' => 'queued'], 202);
        });
    }
}
