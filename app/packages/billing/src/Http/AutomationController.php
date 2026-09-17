<?php
namespace App\Modules\Billing\Http;
use App\Modules\Billing\{StripeGateway, RecurringBilling};
use App\Contracts\Module\AuditSink;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
class AutomationController
{
    public function __construct(
        private StripeGateway $stripe,
        private RecurringBilling $billing,
        private AuditSink $audit,
        private DatabaseManager $db,
        private Encrypter $crypt,
        private Hasher $hash,
    ) {}
    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'system_admin', 403);
    }
    private function tenant(Request $r): int
    {
        abort_unless($r->user()->role === 'restaurant_admin', 403);
        return (int) $r->attributes->get('tenant')->id;
    }
    public function index(Request $r): array
    {
        $admin = $r->user()->role === 'system_admin';
        if ($admin) {
            $this->admin($r);
        } else {
            $tenant = $this->tenant($r);
        }
        $s = $this->stripe->settings();
        return [
            'settings' => $admin
                ? [
                    'enabled' => (bool) $s->enabled,
                    'live' => (bool) $s->live,
                    'send_invoices' => (bool) $s->send_invoices,
                    'send_reminders' => (bool) $s->send_reminders,
                    'reminder_days' => $s->reminder_days,
                    'revision' => $s->revision,
                    'configured' => (bool) $s->secrets,
                ]
                : ['enabled' => (bool) $s->enabled, 'live' => (bool) $s->live],
            'subscriptions' => $this->db
                ->table('billing_subscriptions')
                ->when(!$admin, fn($q) => $q->where('tenant_id', $tenant))
                ->select(
                    'id',
                    'tenant_id',
                    'module_code',
                    'amount_cents',
                    'state',
                    'live',
                    'cancel_at_period_end',
                    'period_end',
                    'synced_at',
                    'sync_error',
                )
                ->latest('id')
                ->paginate(25),
            'deliveries' => $admin
                ? $this->db->table('billing_deliveries')->latest('id')->limit(100)->get()
                : [],
            'products' => $this->db
                ->table('billing_products')
                ->where('available', true)
                ->where('amount_cents', '>', 0)
                ->get(),
        ];
    }
    public function settings(Request $r): array
    {
        $this->admin($r);
        $d = $r->validate([
            'enabled' => 'required|boolean',
            'live' => 'required|boolean',
            'send_invoices' => 'required|boolean',
            'send_reminders' => 'required|boolean',
            'reminder_days' => 'required|integer|min:3|max:60',
            'revision' => 'required|integer|min:0',
            'api_key' => 'nullable|string|max:250',
            'webhook_secret' => 'nullable|string|max:250',
            'password' => 'required|string',
            'confirmed' => 'required|accepted',
        ]);
        abort_unless($this->hash->check($d['password'], $r->user()->password), 403, 'Kennwort stimmt nicht.');
        return $this->db->transaction(function () use ($d) {
            $s = $this->db->table('billing_automation')->where('id', 1)->lockForUpdate()->first();
            abort_unless(
                (int) $s->revision === (int) $d['revision'],
                409,
                'Einstellungen wurden geändert. Neu laden.',
            );
            $secrets = $this->stripe->secrets();
            foreach (['api_key', 'webhook_secret'] as $key) {
                if (!empty($d[$key])) {
                    $secrets[$key] = $d[$key];
                }
            }
            if ($d['enabled']) {
                abort_unless(
                    preg_match(
                        $d['live'] ? '/^sk_live_[a-zA-Z0-9]+$/D' : '/^sk_test_[a-zA-Z0-9]+$/D',
                        $secrets['api_key'] ?? '',
                    ) && preg_match('/^whsec_[a-zA-Z0-9]+$/D', $secrets['webhook_secret'] ?? ''),
                    422,
                    'Passenden API-Schlüssel und Webhook-Schlüssel hinterlegen.',
                );
                abort_unless(
                    str_starts_with(config('app.url'), 'https://'),
                    422,
                    'Eine öffentliche HTTPS-Anwendungsadresse ist erforderlich.',
                );
                $seller = json_decode(
                    $this->db->table('billing_settings')->where('id', 1)->value('data'),
                    true,
                );
                abort_unless(
                    isset($seller['name'], $seller['tax_rate_bps'], $seller['tax_id']),
                    422,
                    'Zuerst den Rechnungsaussteller einrichten.',
                );
            }
            if ((bool) $s->live !== (bool) $d['live']) {
                abort_if(
                    $this->db
                        ->table('billing_subscriptions')
                        ->whereNotIn('state', ['canceled', 'expired', 'incomplete_expired'])
                        ->exists(),
                    409,
                    'Vor Moduswechsel alle Abonnements beenden.',
                );
            }
            $this->db
                ->table('billing_automation')
                ->where('id', 1)
                ->update([
                    'enabled' => $d['enabled'],
                    'live' => $d['live'],
                    'send_invoices' => $d['send_invoices'],
                    'send_reminders' => $d['send_reminders'],
                    'reminder_days' => $d['reminder_days'],
                    'revision' => $s->revision + 1,
                    'secrets' => $this->crypt->encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)),
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.automation_configured', '1');
            return ['status' => 'saved'];
        });
    }
    public function checkout(Request $r): array
    {
        $tenant = $this->tenant($r);
        $d = $r->validate([
            'module_code' => 'required|string|max:60',
            'expected_amount_cents' => 'required|integer|min:1',
            'confirmed' => 'required|accepted',
        ]);
        $s = $this->db->transaction(function () use ($tenant, $d) {
            $settings = $this->db->table('billing_automation')->where('id', 1)->lockForUpdate()->first();
            abort_unless($settings->enabled, 422, 'Automatische Zahlung ist noch nicht eingerichtet.');
            $product = $this->db
                ->table('billing_products')
                ->where('module_code', $d['module_code'])
                ->where('available', true)
                ->first();
            abort_unless(
                $product &&
                    $product->currency === 'EUR' &&
                    (int) $product->amount_cents === (int) $d['expected_amount_cents'],
                409,
                'Angebot neu laden.',
            );
            $profile = $this->db->table('billing_profiles')->where('tenant_id', $tenant)->first();
            abort_unless($profile, 422, 'Zuerst die Rechnungsadresse speichern.');
            $old = $this->db
                ->table('billing_subscriptions')
                ->where('tenant_id', $tenant)
                ->where('module_code', $d['module_code'])
                ->whereNotIn('state', ['canceled', 'expired', 'incomplete_expired'])
                ->first();
            if ($old) {
                abort_unless(
                    $old->state === 'checkout',
                    409,
                    'Für dieses Modul besteht bereits ein Abonnement.',
                );
                abort_unless(
                    (int) $old->amount_cents === (int) $product->amount_cents,
                    409,
                    'Zuerst den bisherigen Checkout beenden.',
                );
                return $old;
            }
            abort_if(
                $this->db
                    ->table('billing_orders')
                    ->where('tenant_id', $tenant)
                    ->where('module_code', $d['module_code'])
                    ->where('status', 'pending')
                    ->exists(),
                409,
                'Zuerst die offene manuelle Bestellung klären.',
            );
            abort_if(
                $this->db
                    ->table('billing_entitlements')
                    ->where('tenant_id', $tenant)
                    ->where('module_code', $d['module_code'])
                    ->where('paid_until', '>', now())
                    ->exists(),
                409,
                'Abonnement erst nach Ende des bereits bezahlten Zeitraums starten.',
            );
            $id = $this->db
                ->table('billing_subscriptions')
                ->insertGetId([
                    'reference' => (string) Str::uuid(),
                    'tenant_id' => $tenant,
                    'module_code' => $d['module_code'],
                    'amount_cents' => $product->amount_cents,
                    'live' => $settings->live,
                    'consented_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.subscription_requested', (string) $id);
            return $this->db->table('billing_subscriptions')->find($id);
        });
        if ($s->checkout_id) {
            $session = $this->stripe->request('GET', 'checkout/sessions/' . $s->checkout_id);
            if ($session['status'] === 'expired') {
                $this->db
                    ->table('billing_subscriptions')
                    ->where('id', $s->id)
                    ->update(['state' => 'expired', 'updated_at' => now()]);
                abort(409, 'Checkout abgelaufen. Abonnement erneut starten.');
            }
            if ($session['status'] === 'complete') {
                if (!empty($session['subscription'])) {
                    $this->billing->syncSubscription($session['subscription']);
                }
                abort(409, 'Checkout abgeschlossen. Zahlungsstatus wird abgeglichen.');
            }
        } else {
            // Fixed idempotency key prevents double subscriptions after timeouts or double-clicks.
            abort_if(
                \Carbon\Carbon::parse($s->created_at)->addHours(23)->isPast(),
                409,
                'Unklaren Checkout beim Anbieter prüfen, bevor ein weiterer angelegt wird.',
            );
            $profile = json_decode(
                $this->db->table('billing_profiles')->where('tenant_id', $tenant)->value('data'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $session = $this->stripe->request(
                'POST',
                'checkout/sessions',
                [
                    'mode' => 'subscription',
                    'customer_email' => $profile['email'],
                    'client_reference_id' => $s->reference,
                    'success_url' => rtrim(config('app.url'), '/') . '/restaurant/',
                    'cancel_url' => rtrim(config('app.url'), '/') . '/restaurant/',
                    'locale' => 'de',
                    'payment_method_types' => ['card'],
                    'subscription_data' => ['metadata' => ['platzhirsch_reference' => $s->reference]],
                    'line_items' => [
                        [
                            'quantity' => 1,
                            'price_data' => [
                                'currency' => 'eur',
                                'unit_amount' => $s->amount_cents,
                                'recurring' => ['interval' => 'month'],
                                'product_data' => ['name' => 'Platzhirsch · ' . $s->module_code],
                            ],
                        ],
                    ],
                ],
                'platzhirsch-checkout-' . $s->reference,
            );
        }
        abort_unless(
            isset($session['url']) && str_starts_with($session['url'], 'https://checkout.stripe.com/'),
            502,
        );
        $this->db
            ->table('billing_subscriptions')
            ->where('id', $s->id)
            ->update([
                'checkout_id' => $session['id'],
                'checkout_url' => $session['url'],
                'checkout_expires' => \Carbon\Carbon::createFromTimestampUTC($session['expires_at']),
                'updated_at' => now(),
            ]);
        return ['url' => $session['url']];
    }
    public function cancel(Request $r, int $id): array
    {
        $tenant = $this->tenant($r);
        $r->validate(['confirmed' => 'required|accepted']);
        $s = $this->db->table('billing_subscriptions')->where('tenant_id', $tenant)->find($id);
        abort_unless($s, 404);
        if ($s->provider_id) {
            $this->stripe->request(
                'POST',
                'subscriptions/' . $s->provider_id,
                ['cancel_at_period_end' => 'true'],
                'platzhirsch-cancel-' . $s->reference,
            );
            $this->billing->syncSubscription($s->provider_id);
        } elseif ($s->checkout_id) {
            $session = $this->stripe->request('GET', 'checkout/sessions/' . $s->checkout_id);
            if ($session['status'] === 'complete') {
                $this->billing->syncSubscription($session['subscription']);
                abort(409, 'Checkout ist abgeschlossen. Abonnement jetzt zum Laufzeitende kündigen.');
            }
            if ($session['status'] === 'open') {
                $this->stripe->request(
                    'POST',
                    'checkout/sessions/' . $s->checkout_id . '/expire',
                    [],
                    'platzhirsch-expire-' . $s->reference,
                );
            }
            $this->db
                ->table('billing_subscriptions')
                ->where('id', $id)
                ->update(['state' => 'expired', 'updated_at' => now()]);
        } else {
            abort(409, 'Unklaren Checkout zuerst über „Abonnement starten“ erneut abgleichen.');
        }
        $this->audit->record('billing.subscription_cancelled', (string) $id);
        return ['status' => 'saved'];
    }
    public function portal(Request $r, int $id): array
    {
        $s = $this->db->table('billing_subscriptions')->where('tenant_id', $this->tenant($r))->find($id);
        abort_unless($s && $s->customer_id, 404);
        $session = $this->stripe->request('POST', 'billing_portal/sessions', [
            'customer' => $s->customer_id,
            'return_url' => rtrim(config('app.url'), '/') . '/restaurant/',
        ]);
        abort_unless(str_starts_with($session['url'] ?? '', 'https://billing.stripe.com/'), 502);
        return ['url' => $session['url']];
    }
    public function webhook(Request $r): array
    {
        abort_if(strlen($r->getContent()) > 1048576, 413);
        $e = $this->stripe->verify($r->getContent(), $r->header('Stripe-Signature', ''));
        $type = $e['type'] ?? '';
        $object = $e['data']['object'] ?? [];
        if (
            in_array(
                $type,
                [
                    'invoice.finalized',
                    'invoice.paid',
                    'invoice.payment_failed',
                    'invoice.voided',
                    'invoice.marked_uncollectible',
                ],
                true,
            )
        ) {
            $this->billing->syncInvoice($object['id']);
        }
        if (
            in_array(
                $type,
                [
                    'customer.subscription.updated',
                    'customer.subscription.deleted',
                    'customer.subscription.created',
                ],
                true,
            )
        ) {
            $this->billing->syncSubscription($object['id']);
        }
        if ($type === 'checkout.session.completed' && !empty($object['subscription'])) {
            $this->billing->syncSubscription($object['subscription']);
        }
        return ['received' => true];
    }
    public function run(Request $r): array
    {
        $this->admin($r);
        \App\Modules\Billing\RunBilling::dispatch();
        return ['status' => 'queued'];
    }
    public function send(Request $r, int $id): array
    {
        $this->admin($r);
        $r->validate(['confirmed' => 'required|accepted']);
        $invoice = $this->db->table('billing_invoices')->find($id);
        abort_unless($invoice && $invoice->status === 'issued', 422);
        $this->billing->enqueue($id);
        $delivery = $this->db
            ->table('billing_deliveries')
            ->where('invoice_id', $id)
            ->where('kind', 'invoice')
            ->where('level', 0)
            ->first();
        \App\Modules\Billing\SendBillingMail::dispatch($delivery->id);
        return ['status' => 'queued'];
    }
    public function retry(Request $r, int $id): array
    {
        $this->admin($r);
        $r->validate(['confirmed' => 'required|accepted']);
        $changed = $this->db
            ->table('billing_deliveries')
            ->where('id', $id)
            ->whereIn('status', ['uncertain', 'pending', 'retry'])
            ->update(['status' => 'retry', 'available_at' => now(), 'error' => null, 'updated_at' => now()]);
        abort_unless($changed, 409, 'Versand läuft oder wurde bereits abgeschlossen.');
        $this->audit->record('billing.delivery_retry', (string) $id);
        \App\Modules\Billing\SendBillingMail::dispatch($id);
        return ['status' => 'queued'];
    }
}
