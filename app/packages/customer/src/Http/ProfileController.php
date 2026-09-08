<?php
namespace App\Modules\Customer\Http;
use App\Contracts\Module\AuditSink;
use Illuminate\Http\Request;
class ProfileController
{
    public function __construct(private AuditSink $audit) {}
    public function profile(Request $r)
    {
        return $r->attributes->get('tenant');
    }
    public function updateProfile(Request $r)
    {
        abort_unless($r->user()->hasPermission('restaurant.profile'), 403);
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:2000',
        ]);
        $tenant = $r->attributes->get('tenant');
        $tenant->update($data);
        $this->audit->record('restaurant.profile_updated', $tenant->id, $tenant->id);
        return $tenant;
    }
}
