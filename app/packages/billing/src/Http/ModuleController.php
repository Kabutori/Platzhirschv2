<?php
namespace App\Modules\Billing\Http;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use App\Core\Module\ModuleRegistry;
use App\Contracts\Module\{ActivationDispatcher, AuditSink};
use Carbon\Carbon;
class ModuleController
{
    public function __construct(
        private DatabaseManager $db,
        private ModuleRegistry $registry,
        private ActivationDispatcher $activation,
        private AuditSink $audit,
    ) {}
    private function tenant(Request $r): int
    {
        abort_unless($r->user()->role === 'restaurant_admin', 403);
        return $r->attributes->get('tenant')->id;
    }
    public function catalog(Request $r): array
    {
        $tenant = $this->tenant($r);
        return [
            'products' => $this->db->table('billing_products')->get(),
            'entitlements' => $this->db->table('billing_entitlements')->where('tenant_id', $tenant)->get(),
            'orders' => $this->db
                ->table('billing_orders')
                ->where('tenant_id', $tenant)
                ->latest('id')
                ->limit(50)
                ->get(),
            'core_modules' => array_values(
                array_map(
                    fn($m) => $m['code'],
                    array_filter(
                        $this->registry->catalog(),
                        fn($m) => in_array(
                            $m['code'],
                            ['identity', 'customer', 'reservation', 'widget', 'support'],
                            true,
                        ),
                    ),
                ),
            ),
            'payment_mode' => 'external_confirmation',
        ];
    }
    public function order(Request $r)
    {
        $tenant = $this->tenant($r);
        $d = $r->validate([
            'module_code' => 'required|string|max:60',
            'request_key' => 'required|uuid',
            'expected_amount_cents' => 'required|integer|min:0',
        ]);
        return $this->db->transaction(function () use ($tenant, $d) {
            $this->db
                ->table('billing_products')
                ->where('module_code', $d['module_code'])
                ->lockForUpdate()
                ->first();
            $existing = $this->db
                ->table('billing_orders')
                ->where('tenant_id', $tenant)
                ->where('request_key', $d['request_key'])
                ->first();
            if ($existing) {
                abort_unless($existing->module_code === $d['module_code'], 409);
                return response()->json($existing, 200);
            }
            abort_if(
                $this->db
                    ->table('billing_orders')
                    ->where('tenant_id', $tenant)
                    ->where('module_code', $d['module_code'])
                    ->where('status', 'pending')
                    ->exists(),
                409,
                'Für dieses Modul wartet bereits eine Bestellung auf Zahlung.',
            );
            $product = $this->db
                ->table('billing_products')
                ->where('module_code', $d['module_code'])
                ->where('available', true)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $product && $product->amount_cents !== null,
                422,
                'Modul wird noch nicht angeboten.',
            );
            $this->registry->get($d['module_code']);
            abort_unless(
                (int) $product->amount_cents === (int) $d['expected_amount_cents'],
                409,
                'Preis wurde geändert. Angebot neu laden.',
            );
            $id = $this->db->table('billing_orders')->insertGetId([
                'tenant_id' => $tenant,
                'module_code' => $d['module_code'],
                'request_key' => $d['request_key'],
                'amount_cents' => $product->amount_cents,
                'currency' => $product->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('billing.order_created', (string) $id);
            return response()->json($this->db->table('billing_orders')->find($id), 201);
        });
    }
    public function activate(Request $r, string $code)
    {
        $tenant = $this->tenant($r);
        $d = $r->validate(['enabled' => 'required|boolean']);
        return $this->db->transaction(function () use ($tenant, $code, $d) {
            $entitlement = $this->db
                ->table('billing_entitlements')
                ->where('tenant_id', $tenant)
                ->where('module_code', $code)
                ->lockForUpdate()
                ->first();
            abort_unless($entitlement, 422, 'Modul zuerst bestellen und freigeben lassen.');
            abort_if($entitlement->status === 'activating', 409, 'Aktivierung läuft bereits.');
            if (!$d['enabled']) {
                foreach ($this->registry->catalog() as $m) {
                    if (isset($m['dependencies'][$code])) {
                        abort_if(
                            $this->db
                                ->table('billing_entitlements')
                                ->where('tenant_id', $tenant)
                                ->where('module_code', $m['code'])
                                ->where('status', 'active')
                                ->exists(),
                            409,
                            'Ein aktives Modul benötigt dieses Modul.',
                        );
                    }
                }
                $this->db
                    ->table('billing_entitlements')
                    ->where('id', $entitlement->id)
                    ->update(['status' => 'inactive', 'updated_at' => now()]);
                $this->audit->record('billing.module_disabled', $tenant . ':' . $code);
                return ['status' => 'inactive'];
            }
            abort_unless(
                Carbon::parse($entitlement->paid_until)->isFuture(),
                422,
                'Nutzungszeitraum ist abgelaufen.',
            );
            $module = $this->registry->get($code);
            foreach ($module->dependencies() as $dependency => $version) {
                $this->registry->get($dependency);
                if ($this->db->table('billing_products')->where('module_code', $dependency)->exists()) {
                    abort_unless(
                        $this->db
                            ->table('billing_entitlements')
                            ->where('tenant_id', $tenant)
                            ->where('module_code', $dependency)
                            ->where('status', 'active')
                            ->where('paid_until', '>', now())
                            ->exists(),
                        422,
                        'Benötigtes Modul ist nicht aktiv.',
                    );
                }
            }
            if ($entitlement->status === 'active' && $entitlement->installed_version === $module->version()) {
                return ['status' => 'active'];
            }
            $operation = $this->activation->enable($tenant, $code);
            $this->db
                ->table('billing_entitlements')
                ->where('id', $entitlement->id)
                ->update(['status' => 'activating', 'updated_at' => now()]);
            return response()->json(['status' => 'activating', 'operation_id' => $operation], 202);
        });
    }
    public function administration(Request $r): array
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        return [
            'products' => $this->db->table('billing_products')->get(),
            'orders' => $this->db->table('billing_orders')->latest('id')->limit(100)->get(),
        ];
    }
    public function price(Request $r, string $code)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        $d = $r->validate([
            'amount_cents' => 'required|integer|min:0|max:1000000',
            'available' => 'required|boolean',
        ]);
        $this->registry->get($code);
        abort_unless(
            $this->db
                ->table('billing_products')
                ->where('module_code', $code)
                ->update([...$d, 'updated_at' => now()]),
            404,
        );
        $this->audit->record('billing.price_updated', $code);
        return ['status' => 'saved'];
    }
    public function confirm(Request $r, int $id)
    {
        abort_unless($r->user()->role === 'system_admin', 403);
        $d = $r->validate([
            'payment_reference' => 'required|string|max:120',
            'payment_confirmed' => 'accepted',
        ]);
        return $this->db->transaction(function () use ($id, $d) {
            $order = $this->db->table('billing_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            if ($order->status === 'paid') {
                abort_unless($order->payment_reference === $d['payment_reference'], 409);
                return ['status' => 'paid'];
            }
            abort_unless($order->status === 'pending', 409);
            abort_if(
                $this->db
                    ->table('billing_orders')
                    ->where('payment_reference', $d['payment_reference'])
                    ->exists(),
                422,
                'Zahlungsreferenz bereits verwendet.',
            );
            $this->db
                ->table('billing_products')
                ->where('module_code', $order->module_code)
                ->lockForUpdate()
                ->first();
            $row = $this->db
                ->table('billing_entitlements')
                ->where('tenant_id', $order->tenant_id)
                ->where('module_code', $order->module_code)
                ->lockForUpdate()
                ->first();
            $start =
                $row && Carbon::parse($row->paid_until)->isFuture() ? Carbon::parse($row->paid_until) : now();
            $this->db->table('billing_entitlements')->updateOrInsert(
                ['tenant_id' => $order->tenant_id, 'module_code' => $order->module_code],
                [
                    'paid_until' => $start->addMonthNoOverflow(),
                    'status' => $row->status ?? 'inactive',
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            $this->db
                ->table('billing_orders')
                ->where('id', $id)
                ->update([
                    'status' => 'paid',
                    'payment_reference' => $d['payment_reference'],
                    'updated_at' => now(),
                ]);
            $this->audit->record('billing.payment_confirmed', (string) $id);
            return ['status' => 'paid'];
        });
    }
}
