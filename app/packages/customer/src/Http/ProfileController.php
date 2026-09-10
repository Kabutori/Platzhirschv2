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
            'cuisine' => 'nullable|string|max:120',
            'price_range' => 'nullable|in:budget,moderate,upscale,fine_dining',
            'total_seats' => 'nullable|integer|min:1|max:10000',
            'description' => 'nullable|string|max:5000',
            'website' => 'nullable|url:https,http|max:500',
            'logo_url' => 'nullable|url:https|max:500',
        ]);
        $tenant = $r->attributes->get('tenant');
        $tenant->update($data);
        $this->audit->record('restaurant.profile_updated', $tenant->id, $tenant->id);
        return $tenant;
    }
}
