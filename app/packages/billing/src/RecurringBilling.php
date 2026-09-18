<?php
namespace App\Modules\Billing;
use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use App\Contracts\Module\AuditSink;
class RecurringBilling
{
    public function __construct(
        private StripeGateway $stripe,
        private AuditSink $audit,
        private DatabaseManager $db,
    ) {}
    public function syncSubscription(string $id): ?object
    {
        abort_unless(preg_match('/^sub_[a-zA-Z0-9]+$/D', $id), 422);
        $remote = $this->stripe->request('GET', 'subscriptions/' . $id);
        $reference = $remote['metadata']['platzhirsch_reference'] ?? '';
        return $this->db->transaction(function () use ($remote, $reference, $id) {
            $s = $this->db
                ->table('billing_subscriptions')
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();
            if (!$s) {
                return null;
            } // Other applications may share this Stripe account.
            abort_unless(
                (bool) $s->live === (bool) $remote['livemode'] &&
                    (!$s->provider_id || $s->provider_id === $id),
                409,
            );
            abort_unless(!$s->customer_id || $s->customer_id === $remote['customer'], 409);
            $this->db
                ->table('billing_subscriptions')
                ->where('id', $s->id)
                ->update([
                    'provider_id' => $id,
                    'customer_id' => $remote['customer'],
                    'state' => $remote['status'],
                    'cancel_at_period_end' => (bool) ($remote['cancel_at_period_end'] ?? false),
                    'period_end' => isset($remote['current_period_end'])
                        ? Carbon::createFromTimestampUTC($remote['current_period_end'])
                        : null,
                    'synced_at' => now(),
                    'sync_error' => null,
                    'updated_at' => now(),
                ]);
            return $this->db->table('billing_subscriptions')->find($s->id);
        });
    }
    public function syncInvoice(string $id): ?int
    {
        abort_unless(preg_match('/^in_[a-zA-Z0-9]+$/D', $id), 422);
        $remote = $this->stripe->request('GET', 'invoices/' . $id);
        if (empty($remote['subscription'])) {
            return null;
        }
        $s = $this->syncSubscription($remote['subscription']);
        if (!$s || !in_array($remote['status'], ['open', 'paid', 'void', 'uncollectible'], true)) {
            return null;
        }
        $lines = $remote['lines']['data'] ?? [];
        abort_unless(
            count($lines) === 1 && !($remote['lines']['has_more'] ?? false),
            409,
            'Anbieterrechnung enthält unerwartete Positionen.',
        );
        $line = $lines[0];
        abort_unless(
            ($remote['customer'] ?? null) === $s->customer_id &&
                ($remote['currency'] ?? null) === 'eur' &&
                (int) $remote['total'] === (int) $s->amount_cents &&
                (int) $line['amount'] === (int) $s->amount_cents &&
                !($line['proration'] ?? false) &&
                ($line['quantity'] ?? 0) === 1,
            409,
            'Anbieterrechnung weicht vom vereinbarten Abonnement ab.',
        );
        $from = Carbon::createFromTimestampUTC($line['period']['start']);
        $until = Carbon::createFromTimestampUTC($line['period']['end']);
        abort_unless($until->greaterThan($from), 409);
        return $this->db->transaction(function () use ($remote, $id, $s, $from, $until) {
            $settings = $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            $invoice = $this->db
                ->table('billing_invoices')
                ->where('provider_id', $id)
                ->lockForUpdate()
                ->first();
            $paid = $remote['status'] === 'paid';
            if (!$invoice) {
                if (in_array($remote['status'], ['void', 'uncollectible'], true)) {
                    return null;
                }
                $seller = json_decode($settings->data, true, flags: JSON_THROW_ON_ERROR);
                $profile = $this->db->table('billing_profiles')->where('tenant_id', $s->tenant_id)->first();
                abort_unless(
                    $profile && isset($seller['tax_rate_bps'], $seller['name'], $seller['tax_id']),
                    422,
                    'Rechnungsstammdaten fehlen.',
                );
                $buyer = json_decode($profile->data, true, flags: JSON_THROW_ON_ERROR);
                abort_unless(
                    isset(
                        $buyer['email'],
                        $buyer['name'],
                        $buyer['street'],
                        $buyer['postal_code'],
                        $buyer['city'],
                    ),
                    422,
                );
                $gross = (int) $s->amount_cents;
                $rate = (int) $seller['tax_rate_bps'];
                $net = intdiv($gross * 10000 + intdiv(10000 + $rate, 2), 10000 + $rate);
                $counter = $s->live
                    ? $settings
                    : $this->db->table('billing_automation')->where('id', 1)->lockForUpdate()->first();
                $field = $s->live ? 'next_number' : 'next_test_number';
                $n = (int) $counter->$field;
                abort_if($n > 999999999, 422);
                $this->db
                    ->table($s->live ? 'billing_settings' : 'billing_automation')
                    ->where('id', 1)
                    ->update([$field => $n + 1, 'updated_at' => now()]);
                $number =
                    ($s->live ? 'PH-' : 'TEST-PH-') .
                    now()->format('Y') .
                    '-' .
                    str_pad((string) $n, 6, '0', STR_PAD_LEFT);
                $order = $this->db->table('billing_orders')->insertGetId([
                    'tenant_id' => $s->tenant_id,
                    'module_code' => $s->module_code,
                    'request_key' => (string) Str::uuid(),
                    'amount_cents' => $gross,
                    'currency' => 'EUR',
                    'status' => 'pending',
                    'period_start' => $from,
                    'period_end' => $until,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $invoiceId = $this->db->table('billing_invoices')->insertGetId([
                    'tenant_id' => $s->tenant_id,
                    'order_id' => $order,
                    'provider_id' => $id,
                    'subscription_id' => $s->id,
                    'kind' => 'invoice',
                    'status' => 'issued',
                    'number' => $number,
                    'total_cents' => $gross,
                    'tax_cents' => $gross - $net,
                    'payment_status' => 'unpaid',
                    'due_at' => isset($remote['due_date'])
                        ? Carbon::createFromTimestampUTC($remote['due_date'])
                        : $from->copy()->addDays(7),
                    'payload' => json_encode(
                        [
                            'seller' => $seller,
                            'buyer' => $buyer,
                            'module' => $s->module_code,
                            'test_mode' => !(bool) $s->live,
                            'description' => 'Modul ' . $s->module_code . ' · monatliches Abonnement',
                            'service_start' => $from->toDateString(),
                            'service_end' => $until->toDateString(),
                            'payment_reference' => 'stripe:' . $id,
                            'paid_at' => null,
                            'currency' => 'EUR',
                            'gross_cents' => $gross,
                            'net_cents' => $net,
                            'tax_cents' => $gross - $net,
                            'tax_rate_bps' => $rate,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                    'issued_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $invoice = $this->db->table('billing_invoices')->find($invoiceId);
                $this->audit->record('billing.recurring_invoice_issued', (string) $invoiceId);
            }
            if ($paid && $invoice->payment_status !== 'paid') {
                abort_unless(
                    (int) $remote['amount_paid'] === (int) $s->amount_cents &&
                        (int) $remote['amount_remaining'] === 0,
                    409,
                );
                if ($s->live) {
                    $this->db
                        ->table('billing_products')
                        ->where('module_code', $s->module_code)
                        ->lockForUpdate()
                        ->first();
                    $entitlement = $this->db
                        ->table('billing_entitlements')
                        ->where('tenant_id', $s->tenant_id)
                        ->where('module_code', $s->module_code)
                        ->lockForUpdate()
                        ->first();
                    $end =
                        $entitlement && Carbon::parse($entitlement->paid_until)->greaterThan($until)
                            ? $entitlement->paid_until
                            : $until;
                    $this->db->table('billing_entitlements')->updateOrInsert(
                        ['tenant_id' => $s->tenant_id, 'module_code' => $s->module_code],
                        [
                            'paid_until' => $end,
                            'status' => $entitlement->status ?? 'inactive',
                            'created_at' => $entitlement->created_at ?? now(),
                            'updated_at' => now(),
                        ],
                    );
                }
                $this->db
                    ->table('billing_orders')
                    ->where('id', $invoice->order_id)
                    ->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'payment_reference' => 'stripe:' . $id,
                        'updated_at' => now(),
                    ]);
                $this->db
                    ->table('billing_invoices')
                    ->where('id', $invoice->id)
                    ->update(['payment_status' => 'paid', 'settled_at' => now(), 'updated_at' => now()]);
                $this->audit->record('billing.recurring_payment_confirmed', (string) $invoice->id);
            } elseif (!$paid && $invoice->payment_status !== 'paid') {
                $this->db
                    ->table('billing_invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'payment_status' => $remote['status'] === 'open' ? 'unpaid' : $remote['status'],
                        'updated_at' => now(),
                    ]);
            }
            return (int) $invoice->id;
        });
    }
    public function enqueue(int $id, string $kind = 'invoice', int $level = 0): void
    {
        $this->db
            ->table('billing_deliveries')
            ->insertOrIgnore([
                'invoice_id' => $id,
                'kind' => $kind,
                'level' => $level,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
    public function run(): array
    {
        $settings = $this->stripe->settings();
        $errors = 0;
        $this->db
            ->table('billing_deliveries')
            ->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'uncertain',
                'error' => 'Versand abgebrochen. Zustellung vor erneutem Versand prüfen.',
                'updated_at' => now(),
            ]);
        if ($settings->enabled) {
            $this->db
                ->table('billing_subscriptions')
                ->where('live', $settings->live)
                ->where('state', 'checkout')
                ->whereNotNull('checkout_id')
                ->orderBy('id')
                ->chunkById(50, function ($rows) use (&$errors) {
                    foreach ($rows as $s) {
                        try {
                            $session = $this->stripe->request('GET', 'checkout/sessions/' . $s->checkout_id);
                            if ($session['status'] === 'complete' && !empty($session['subscription'])) {
                                $this->syncSubscription($session['subscription']);
                            } elseif ($session['status'] === 'expired') {
                                $this->db
                                    ->table('billing_subscriptions')
                                    ->where('id', $s->id)
                                    ->update(['state' => 'expired', 'updated_at' => now()]);
                            }
                        } catch (\Throwable $e) {
                            $errors++;
                        };
                    }
                });
            $this->db
                ->table('billing_subscriptions')
                ->where('live', $settings->live)
                ->whereNotNull('provider_id')
                ->orderBy('id')
                ->chunkById(50, function ($rows) use (&$errors) {
                    foreach ($rows as $s) {
                        try {
                            $this->syncSubscription($s->provider_id);
                            $after = null;
                            do {
                                $page = $this->stripe->request('GET', 'invoices', [
                                    'subscription' => $s->provider_id,
                                    'limit' => 100,
                                    ...$after ? ['starting_after' => $after] : [],
                                ]);
                                foreach ($page['data'] as $i) {
                                    $this->syncInvoice($i['id']);
                                    $after = $i['id'];
                                }
                            } while (!empty($page['has_more']) && $after);
                        } catch (\Throwable $e) {
                            $errors++;
                            $this->db
                                ->table('billing_subscriptions')
                                ->where('id', $s->id)
                                ->update([
                                    'sync_error' =>
                                        'Abgleich fehlgeschlagen. Anbieter und Rechnungsdaten prüfen.',
                                    'updated_at' => now(),
                                ]);
                        };
                    }
                });
        }
        if ($settings->send_invoices) {
            $this->db
                ->table('billing_invoices')
                ->where('status', 'issued')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $i) {
                        $this->enqueue($i->id);
                    }
                });
        }
        if ($settings->send_reminders) {
            $this->db
                ->table('billing_invoices')
                ->where('status', 'issued')
                ->where('kind', 'invoice')
                ->where('payment_status', 'unpaid')
                ->where('due_at', '<', now())
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($settings) {
                    foreach ($rows as $i) {
                        // No stale provider state can trigger a payment reminder.
                        if ($i->provider_id) {
                            try {
                                $this->syncInvoice($i->provider_id);
                            } catch (\Throwable $e) {
                                continue;
                            };
                        }
                        if (
                            $this->db
                                ->table('billing_invoices')
                                ->where('id', $i->id)
                                ->value('payment_status') !== 'unpaid'
                        ) {
                            continue;
                        }
                        $previous = $this->db
                            ->table('billing_deliveries')
                            ->where('invoice_id', $i->id)
                            ->where('kind', 'reminder')
                            ->orderByDesc('level')
                            ->first();
                        if (
                            $previous &&
                            ($previous->status !== 'sent' ||
                                $previous->level >= 3 ||
                                Carbon::parse($previous->sent_at)
                                    ->addDays($settings->reminder_days)
                                    ->isFuture())
                        ) {
                            continue;
                        }
                        $this->enqueue($i->id, 'reminder', ($previous->level ?? 0) + 1);
                    }
                });
        }
        $this->db
            ->table('billing_deliveries')
            ->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $d) {
                    SendBillingMail::dispatch($d->id);
                }
            });
        return ['sync_errors' => $errors];
    }
}
