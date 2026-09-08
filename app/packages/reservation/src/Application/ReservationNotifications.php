<?php
namespace App\Modules\Reservation\Application;
use Illuminate\Support\Facades\{DB, Mail, Http};
use Carbon\CarbonImmutable;
class ReservationNotifications
{
    public function readiness(): array
    {
        return [
            'email' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host'),
            'sms' =>
                (bool) (preg_match(
                    '/^AC[0-9a-f]{32}$/i',
                    (string) config('reservation_notifications.sms.sid'),
                ) &&
                    config('reservation_notifications.sms.token') &&
                    preg_match(
                        '/^\+[1-9][0-9]{7,14}$/',
                        (string) config('reservation_notifications.sms.from'),
                    )),
        ];
    }
    public function enqueue(object $r): void
    {
        $db = DB::connection('tenant');
        $settings = $db->table('reservation_notification_settings')->find(1);
        $db->table('reservation_notifications')
            ->where('reservation_id', $r->id)
            ->where('status', 'pending')
            ->update(['status' => 'superseded']);
        if (!$settings) {
            return;
        }
        if (!in_array($r->status, ['confirmed', 'cancelled'], true)) {
            return;
        }
        foreach (['email', 'sms'] as $channel) {
            if (!$settings->{$channel . '_enabled'}) {
                continue;
            }
            $events = ['confirmation' => now()->utc()];
            $start = CarbonImmutable::parse($r->starts_at, 'UTC');
            if ($r->status === 'confirmed' && $settings->reminder_minutes > 0 && $start->isFuture()) {
                $events['reminder'] = $start->subMinutes($settings->reminder_minutes)->max(now()->utc());
            }
            foreach ($events as $kind => $due) {
                $db->table('reservation_notifications')->insertOrIgnore([
                    'reservation_id' => $r->id,
                    'version' => $r->version,
                    'channel' => $channel,
                    'kind' => $kind,
                    'due_at' => $due,
                ]);
            }
        }
    }
    public function dispatch(string $restaurant, string $timezone, int $limit = 20): void
    {
        $db = DB::connection('tenant');
        $settings = $db->table('reservation_notification_settings')->find(1);
        if (!$settings) {
            return;
        }
        $ready = $this->readiness();
        $ids = $db
            ->table('reservation_notifications')
            ->where('status', 'pending')
            ->where('due_at', '<=', now()->utc())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($ids as $id) {
            // Claim before sending: crashes/unknown delivery must never trigger automatic duplicate SMS.
            $event = $db->transaction(function () use ($db, $id, $settings, $ready) {
                $e = $db->table('reservation_notifications')->where('id', $id)->lockForUpdate()->first();
                if (!$e || $e->status !== 'pending') {
                    return null;
                }
                $r = $db->table('reservations')->find($e->reservation_id);
                if (
                    !$r ||
                    (int) $r->version !== (int) $e->version ||
                    !$settings->{$e->channel . '_enabled'} ||
                    ($e->kind === 'reminder' &&
                        ($r->status !== 'confirmed' ||
                            CarbonImmutable::parse($r->starts_at, 'UTC')->isPast()))
                ) {
                    $db->table('reservation_notifications')
                        ->where('id', $id)
                        ->update(['status' => 'superseded']);
                    return null;
                }
                if (!$ready[$e->channel]) {
                    return null;
                }
                $db->table('reservation_notifications')
                    ->where('id', $id)
                    ->update(['status' => 'sending', 'processed_at' => now()->utc()]);
                return [$e, $r];
            });
            if (!$event) {
                continue;
            }
            [$e, $r] = $event;
            $recipient = $e->channel === 'email' ? $r->email : $r->phone;
            $valid =
                $e->channel === 'email'
                    ? filter_var($recipient, FILTER_VALIDATE_EMAIL)
                    : preg_match('/^\+[1-9][0-9]{7,14}$/', (string) $recipient);
            $status = 'invalid_recipient';
            if ($valid) {
                $when = CarbonImmutable::parse($r->starts_at, 'UTC')
                    ->setTimezone($timezone)
                    ->format('d.m.Y H:i');
                $text =
                    $restaurant .
                    ': ' .
                    ($r->status === 'cancelled'
                        ? 'Reservierung storniert'
                        : ($e->kind === 'reminder'
                            ? 'Erinnerung an Ihre Reservierung'
                            : 'Reservierung bestätigt')) .
                    ' · ' .
                    $when .
                    ' (' .
                    $timezone .
                    ') · ' .
                    $r->party_size .
                    ' Personen · Buchung ' .
                    $r->id .
                    '. Bei Rückfragen kontaktieren Sie bitte das Restaurant.';
                try {
                    if ($e->channel === 'email') {
                        Mail::raw(
                            $text,
                            fn($m) => $m->to($recipient)->subject('Ihre Reservierung · ' . $restaurant),
                        );
                    } else {
                        $sid = config('reservation_notifications.sms.sid');
                        $response = Http::asForm()
                            ->withBasicAuth($sid, config('reservation_notifications.sms.token'))
                            ->timeout(15)
                            ->withoutRedirecting()
                            ->post('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json', [
                                'From' => config('reservation_notifications.sms.from'),
                                'To' => $recipient,
                                'Body' => $text,
                            ]);
                        if (!$response->successful()) {
                            $db->table('reservation_notifications')
                                ->where('id', $id)
                                ->update(['status' => 'rejected']);
                            continue;
                        }
                    }
                    $status = 'accepted';
                } catch (\Throwable) {
                    $status = 'unknown';
                }
            }
            $db->table('reservation_notifications')
                ->where('id', $id)
                ->update(['status' => $status, 'processed_at' => now()->utc()]);
        }
    }
}
