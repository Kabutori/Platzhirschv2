<?php
namespace App\Modules\Billing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Mail\Factory;
class SendBillingMail implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 60;
    public function __construct(public int $deliveryId) {}
    public function handle(RecurringBilling $billing, DatabaseManager $db, Factory $mail): void
    {
        $delivery = $db->table('billing_deliveries')->find($this->deliveryId);
        if (!$delivery || !in_array($delivery->status, ['pending', 'retry'], true)) {
            return;
        }
        $invoice = $db->table('billing_invoices')->find($delivery->invoice_id);
        if ($delivery->kind === 'reminder' && $invoice?->provider_id) {
            try {
                $billing->syncInvoice($invoice->provider_id);
            } catch (\Throwable $e) {
                $db->table('billing_deliveries')
                    ->where('id', $delivery->id)
                    ->update([
                        'error' => 'Zahlungsstatus konnte nicht geprüft werden.',
                        'available_at' => now()->addHour(),
                    ]);
                return;
            }
        }
        $claimed = $db->transaction(function () use ($delivery, $db) {
            $d = $db->table('billing_deliveries')->where('id', $delivery->id)->lockForUpdate()->first();
            if (!in_array($d->status, ['pending', 'retry'], true)) {
                return null;
            }
            $i = $db->table('billing_invoices')->find($d->invoice_id);
            if (
                !$i ||
                $i->status !== 'issued' ||
                ($d->kind === 'reminder' &&
                    ($i->payment_status !== 'unpaid' ||
                        !$i->due_at ||
                        $i->due_at >= now()->toDateTimeString()))
            ) {
                $db->table('billing_deliveries')
                    ->where('id', $d->id)
                    ->update(['status' => 'skipped', 'updated_at' => now()]);
                return null;
            }
            if (config('mail.default') !== 'smtp') {
                $db->table('billing_deliveries')
                    ->where('id', $d->id)
                    ->update([
                        'error' => 'Produktiven SMTP-Versand in den Systemeinstellungen aktivieren.',
                        'available_at' => now()->addHour(),
                        'updated_at' => now(),
                    ]);
                return null;
            }
            $db->table('billing_deliveries')
                ->where('id', $d->id)
                ->update([
                    'status' => 'sending',
                    'attempts' => $d->attempts + 1,
                    'error' => null,
                    'updated_at' => now(),
                ]);
            return $i;
        });
        if (!$claimed) {
            return;
        }
        try {
            $p = json_decode($claimed->payload, true, flags: JSON_THROW_ON_ERROR);
            $reminder = $delivery->kind === 'reminder';
            $subject = ($reminder ? 'Zahlungserinnerung ' . $delivery->level . ' · ' : '') . $claimed->number;
            $amount = number_format($claimed->total_cents / 100, 2, ',', '.') . ' EUR';
            $text = $reminder
                ? "Für die Rechnung {$claimed->number} über {$amount} liegt noch keine Zahlung vor. Bitte prüfen Sie Ihre Zahlungsmethode im Restaurantportal unter Abrechnung. Es werden keine Mahngebühren berechnet."
                : "Anbei erhalten Sie Ihren Beleg {$claimed->number}. Ihre Rechnungen und den aktuellen Zahlungsstatus finden Sie auch im Restaurantportal unter Abrechnung.";
            $pdf = (new \App\Core\Export\Pdf())->render((new InvoicePrint())->render((array) $claimed, $p));
            $mail->mailer()->raw($text, function ($message) use ($p, $subject, $pdf, $claimed) {
                $message
                    ->to($p['buyer']['email'])
                    ->subject($subject)
                    ->attachData($pdf, $claimed->number . '.pdf', ['mime' => 'application/pdf']);
            });
            $db->table('billing_deliveries')
                ->where('id', $delivery->id)
                ->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // SMTP may have accepted a message before the connection failed. Never blindly resend.
            $db->table('billing_deliveries')
                ->where('id', $delivery->id)
                ->update([
                    'status' => 'uncertain',
                    'error' =>
                        'SMTP-Versand nicht bestätigt. Zustellung prüfen, dann bei Bedarf bewusst erneut senden.',
                    'updated_at' => now(),
                ]);
        }
    }
}
