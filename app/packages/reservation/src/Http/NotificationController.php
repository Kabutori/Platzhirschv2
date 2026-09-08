<?php
namespace App\Modules\Reservation\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Modules\Reservation\Application\ReservationNotifications;
class NotificationController
{
    public function index(Request $r, ReservationNotifications $service)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        return [
            'settings' => DB::connection('tenant')->table('reservation_notification_settings')->find(1),
            'ready' => $service->readiness(),
            'recent' => DB::connection('tenant')
                ->table('reservation_notifications')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ];
    }
    public function save(Request $r, ReservationNotifications $service)
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        $data = $r->validate([
            'email_enabled' => 'required|boolean',
            'sms_enabled' => 'required|boolean',
            'reminder_minutes' => 'required|integer|min:0|max:10080',
        ]);
        $ready = $service->readiness();
        foreach (['email', 'sms'] as $channel) {
            abort_if(
                $data[$channel . '_enabled'] && !$ready[$channel],
                422,
                'Versandkanal ist auf dem Server noch nicht eingerichtet.',
            );
        }
        DB::connection('tenant')->table('reservation_notification_settings')->where('id', 1)->update($data);
        app(\App\Contracts\Module\AuditSink::class)->record(
            'reservation.notifications_configured',
            1,
            $r->attributes->get('tenant')->id,
        );
        return $this->index($r, $service);
    }
}
