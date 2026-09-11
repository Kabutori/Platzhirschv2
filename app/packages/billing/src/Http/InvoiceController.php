<?php
namespace App\Modules\Billing\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use App\Contracts\Module\{AuditSink, TenantDirectory};
use App\Modules\Billing\InvoicePrint;
class InvoiceController
{
    public function __construct(
        private DatabaseManager $db,
        private AuditSink $audit,
        private TenantDirectory $tenants,
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
    private function decode($v): array
    {
        return json_decode($v ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }
    private function partyRules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'street' => 'required|string|max:160',
            'postal_code' => 'required|string|max:20',
            'city' => 'required|string|max:100',
            'country' => 'required|in:DE',
            'email' => 'required|email|max:190',
            'tax_id' => 'nullable|string|max:80',
        ];
    }
    public function index(Request $r): array
    {
        $this->admin($r);
        $s = $this->db->table('billing_settings')->find(1);
        return [
            'settings' => [...$this->decode($s->data), 'revision' => $s->revision],
            'invoices' => $this->db
                ->table('billing_invoices')
                ->select(
                    'id',
                    'tenant_id',
                    'order_id',
                    'original_id',
                    'kind',
                    'status',
                    'number',
                    'total_cents',
                    'tax_cents',
                    'issued_at',
                    'created_at',
                )
                ->latest('id')
                ->paginate(25),
            'subscriptions' => $this->db
                ->table('billing_entitlements')
                ->orderBy('paid_until')
                ->limit(200)
                ->get(),
            'profiles' => $this->db
                ->table('billing_profiles')
                ->orderBy('tenant_id')
                ->limit(200)
                ->get()
                ->map(
                    fn($p) => [
                        'tenant_id' => $p->tenant_id,
                        'revision' => $p->revision,
                        ...$this->decode($p->data),
                    ],
                ),
        ];
    }
    public function settings(Request $r): array
    {
        $this->admin($r);
        $d = $r->validate([
            ...$this->partyRules(),
            'tax_id' => 'required|string|max:80',
            'tax_rate_bps' => 'required|integer|in:0,700,1900',
            'tax_note' => 'nullable|string|max:250',
            'payment_note' => 'nullable|string|max:500',
            'revision' => 'required|integer|min:0',
        ]);
        abort_if(
            (int) $d['tax_rate_bps'] === 0 && trim($d['tax_note'] ?? '') === '',
            422,
            'Bei 0 % ist eine Steuerbegründung erforderlich.',
        );
        return $this->db->transaction(function () use ($d) {
            $s = $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(
                (int) $s->revision === (int) $d['revision'],
                409,
                'Einstellungen wurden geändert. Neu laden.',
            );
            unset($d['revision']);
            $this->db
                ->table('billing_settings')
                ->where('id', 1)
                ->update([
                    'data' => json_encode($d, JSON_THROW_ON_ERROR),
                    'revision' => $s->revision + 1,
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.issuer_saved', '1');
            return ['status' => 'saved'];
        });
    }
    public function profile(Request $r, ?int $tenant = null): array
    {
        if ($tenant !== null) {
            $this->admin($r);
            abort_unless($this->tenants->exists($tenant), 404);
        } else {
            $tenant = $this->tenant($r);
        }
        $d = $r->validate([...$this->partyRules(), 'revision' => 'required|integer|min:0']);
        return $this->db->transaction(function () use ($tenant, $d) {
            // The existing singleton serializes first profile creation as well as later updates.
            $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            $p = $this->db->table('billing_profiles')->where('tenant_id', $tenant)->lockForUpdate()->first();
            abort_unless(
                (int) ($p->revision ?? 0) === (int) $d['revision'],
                409,
                'Rechnungsadresse wurde geändert. Neu laden.',
            );
            unset($d['revision']);
            $this->db
                ->table('billing_profiles')
                ->updateOrInsert(
                    ['tenant_id' => $tenant],
                    [
                        'data' => json_encode($d, JSON_THROW_ON_ERROR),
                        'revision' => ($p->revision ?? 0) + 1,
                        'created_at' => $p->created_at ?? now(),
                        'updated_at' => now(),
                    ],
                );
            $this->audit->record('billing.profile_saved', (string) $tenant);
            return ['status' => 'saved'];
        });
    }
    public function customer(Request $r): array
    {
        $tenant = $this->tenant($r);
        $p = $this->db->table('billing_profiles')->where('tenant_id', $tenant)->first();
        return [
            'profile' => [...$this->decode($p->data ?? null), 'revision' => $p->revision ?? 0],
            'invoices' => $this->db
                ->table('billing_invoices')
                ->where('tenant_id', $tenant)
                ->whereNotNull('issued_at')
                ->select('id', 'kind', 'status', 'number', 'total_cents', 'issued_at', 'original_id')
                ->latest('id')
                ->paginate(25),
            'subscriptions' => $this->db->table('billing_entitlements')->where('tenant_id', $tenant)->get(),
        ];
    }
    public function draft(Request $r, int $order): mixed
    {
        $this->admin($r);
        $d = $r->validate([
            'service_start' => 'nullable|date_format:Y-m-d',
            'service_end' => 'nullable|date_format:Y-m-d|after:service_start',
        ]);
        return $this->db->transaction(function () use ($order, $d) {
            $s = $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            $o = $this->db->table('billing_orders')->where('id', $order)->lockForUpdate()->first();
            abort_unless($o, 404);
            $existing = $this->db->table('billing_invoices')->where('order_id', $order)->first();
            if ($existing) {
                return response()->json(['id' => $existing->id], 200);
            }
            abort_unless(
                $o->status === 'paid',
                422,
                'Rechnungen werden in dieser Ausbaustufe für bestätigte Zahlungen erstellt.',
            );
            abort_unless($o->currency === 'EUR', 422, 'Nur EUR wird unterstützt.');
            $seller = $this->decode($s->data);
            abort_unless(
                isset($seller['tax_rate_bps'], $seller['name'], $seller['tax_id']),
                422,
                'Zuerst Aussteller und Steuerangaben hinterlegen.',
            );
            $p = $this->db->table('billing_profiles')->where('tenant_id', $o->tenant_id)->first();
            abort_unless($p, 422, 'Zuerst die Rechnungsadresse des Restaurants hinterlegen.');
            $from = $o->period_start ? substr($o->period_start, 0, 10) : $d['service_start'] ?? null;
            $until = $o->period_end ? substr($o->period_end, 0, 10) : $d['service_end'] ?? null;
            abort_unless(
                $from && $until && $until > $from,
                422,
                'Für Altaufträge den tatsächlichen Leistungszeitraum angeben (Enddatum exklusiv).',
            );
            $rate = (int) $seller['tax_rate_bps'];
            $gross = (int) $o->amount_cents;
            $net = intdiv($gross * 10000 + intdiv(10000 + $rate, 2), 10000 + $rate);
            $tax = $gross - $net;
            $payload = [
                'seller' => $seller,
                'buyer' => $this->decode($p->data),
                'module' => $o->module_code,
                'description' => 'Modul ' . $o->module_code . ' · ein Monat Nutzungsrecht',
                'service_start' => $from,
                'service_end' => $until,
                'payment_reference' => $o->payment_reference,
                'paid_at' => $o->paid_at,
                'currency' => 'EUR',
                'gross_cents' => $gross,
                'net_cents' => $net,
                'tax_cents' => $tax,
                'tax_rate_bps' => $rate,
            ];
            $id = $this->db
                ->table('billing_invoices')
                ->insertGetId([
                    'tenant_id' => $o->tenant_id,
                    'order_id' => $o->id,
                    'total_cents' => $gross,
                    'tax_cents' => $tax,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.invoice_drafted', (string) $id);
            return response()->json(['id' => $id], 201);
        });
    }
    private function number($settings): string
    {
        $n = (int) $settings->next_number;
        abort_if($n > 999999999, 422, 'Nummernkreis erschöpft.');
        $this->db
            ->table('billing_settings')
            ->where('id', 1)
            ->update(['next_number' => $n + 1, 'updated_at' => now()]);
        return 'PH-' . now()->format('Y') . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
    public function issue(Request $r, int $id): array
    {
        $this->admin($r);
        $r->validate(['confirmed' => 'required|accepted']);
        return $this->db->transaction(function () use ($id) {
            $s = $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            $i = $this->db->table('billing_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i, 404);
            if ($i->status === 'issued') {
                return ['number' => $i->number];
            }
            abort_unless($i->status === 'draft', 409);
            $number = $this->number($s);
            $this->db
                ->table('billing_invoices')
                ->where('id', $id)
                ->update([
                    'number' => $number,
                    'status' => 'issued',
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.invoice_issued', (string) $id);
            return ['number' => $number];
        });
    }
    public function discard(Request $r, int $id): array
    {
        $this->admin($r);
        return $this->db->transaction(function () use ($id) {
            $i = $this->db->table('billing_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i, 404);
            abort_unless($i->status === 'draft', 409, 'Ausgestellte Belege können nicht gelöscht werden.');
            $this->db->table('billing_invoices')->where('id', $id)->delete();
            $this->audit->record('billing.draft_discarded', (string) $id);
            return ['status' => 'deleted'];
        });
    }
    public function cancel(Request $r, int $id): array
    {
        $this->admin($r);
        $d = $r->validate(['reason' => 'required|string|max:500', 'confirmed' => 'required|accepted']);
        return $this->db->transaction(function () use ($id, $d) {
            $s = $this->db->table('billing_settings')->where('id', 1)->lockForUpdate()->first();
            $i = $this->db->table('billing_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i && $i->kind === 'invoice', 404);
            $old = $this->db->table('billing_invoices')->where('original_id', $id)->first();
            if ($old) {
                return ['id' => $old->id, 'number' => $old->number];
            }
            abort_unless($i->status === 'issued', 409);
            $p = $this->decode($i->payload);
            foreach (['gross_cents', 'net_cents', 'tax_cents'] as $key) {
                $p[$key] = -$p[$key];
            }
            $p['reason'] = $d['reason'];
            $p['original_number'] = $i->number;
            $number = $this->number($s);
            $credit = $this->db
                ->table('billing_invoices')
                ->insertGetId([
                    'tenant_id' => $i->tenant_id,
                    'original_id' => $id,
                    'kind' => 'credit',
                    'status' => 'issued',
                    'number' => $number,
                    'total_cents' => -$i->total_cents,
                    'tax_cents' => -$i->tax_cents,
                    'payload' => json_encode($p, JSON_THROW_ON_ERROR),
                    'issued_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->db
                ->table('billing_invoices')
                ->where('id', $id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit->record('billing.invoice_cancelled', (string) $id);
            return ['id' => $credit, 'number' => $number];
        });
    }
    public function print(Request $r, int $id)
    {
        $query = $this->db->table('billing_invoices')->where('id', $id);
        if ($r->attributes->get('tenant')) {
            $query->where('tenant_id', $this->tenant($r))->whereNotNull('issued_at');
        } else {
            $this->admin($r);
        }
        $i = $query->first();
        abort_unless($i, 404);
        return response((new InvoicePrint())->render((array) $i, $this->decode($i->payload)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' =>
                "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
