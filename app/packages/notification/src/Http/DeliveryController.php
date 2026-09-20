<?php
namespace App\Modules\Notification\Http;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use App\Contracts\Module\TenantRuntime;
class DeliveryController
{
    public function __construct(private DatabaseManager $db, private TenantRuntime $runtime) {}
    public function status(Request $r, string $id)
    {
        $base = rtrim((string) config('app.url'), '/');
        $secret = (string) config('reservation_notifications.sms.token');
        abort_unless(
            str_starts_with($base, 'https://') && $secret !== '' && $r->getQueryString() === null,
            403,
        );
        abort_unless(
            str_starts_with((string) $r->header('Content-Type'), 'application/x-www-form-urlencoded') &&
                strlen($r->getContent()) <= 16384,
            415,
        );
        // Sign the original form values, before TrimStrings/ConvertEmptyStringsToNull.
        $data = [];
        foreach (explode('&', $r->getContent()) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            abort_unless(
                preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $key) && !array_key_exists($key, $data),
                400,
            );
            $data[$key] = urldecode($value);
        }
        ksort($data, SORT_STRING);
        $signed = $base . '/api/notification/status/' . $id;
        foreach ($data as $key => $value) {
            $signed .= $key . $value;
        }
        abort_unless(
            hash_equals(
                base64_encode(hash_hmac('sha1', $signed, $secret, true)),
                (string) $r->header('X-Twilio-Signature'),
            ),
            403,
        );
        abort_unless(($data['AccountSid'] ?? '') === config('reservation_notifications.sms.sid'), 403);
        $state = $data['MessageStatus'] ?? '';
        $sid = $data['MessageSid'] ?? '';
        abort_unless(
            in_array($state, ['queued', 'sending', 'sent', 'delivered', 'failed', 'undelivered'], true) &&
                preg_match('/^SM[0-9a-f]{32}$/i', $sid),
            422,
        );
        $attempt = $this->db
            ->table('notification_attempts')
            ->where('public_id', $id)
            ->where('channel', 'sms')
            ->first();
        abort_unless($attempt, 404);
        $this->runtime->withTenant((int) $attempt->tenant_id, function () use ($attempt, $sid, $state) {
            $db = $this->db->connection('tenant');
            $db->transaction(function () use ($db, $attempt, $sid, $state) {
                $event = $db
                    ->table('reservation_notifications')
                    ->where('id', $attempt->event_id)
                    ->lockForUpdate()
                    ->first();
                $current = $this->db->table('notification_attempts')->find($attempt->id);
                abort_unless(!$current->provider_sid || hash_equals($current->provider_sid, $sid), 403);
                $ranks = [
                    'sending' => 0,
                    'unknown' => 0,
                    'accepted' => 0,
                    'queued' => 1,
                    'sent' => 2,
                    'failed' => 3,
                    'undelivered' => 3,
                    'delivered' => 3,
                ];
                if (
                    in_array($current->status, ['delivered', 'failed', 'undelivered'], true) ||
                    ($ranks[$state] ?? 0) < ($ranks[$current->status] ?? 0)
                ) {
                    return;
                }
                $this->db
                    ->table('notification_attempts')
                    ->where('id', $attempt->id)
                    ->update(['provider_sid' => $sid, 'status' => $state, 'updated_at' => now()]);
                $latest = $this->db
                    ->table('notification_attempts')
                    ->where('tenant_id', $attempt->tenant_id)
                    ->where('event_id', $attempt->event_id)
                    ->max('id');
                if (
                    $event &&
                    (int) $latest === (int) $attempt->id &&
                    !in_array($event->status, ['pending', 'superseded'], true)
                ) {
                    $db->table('reservation_notifications')
                        ->where('id', $attempt->event_id)
                        ->update(['status' => $state, 'processed_at' => now()->utc()]);
                }
            });
        });
        return response()->noContent();
    }
}
