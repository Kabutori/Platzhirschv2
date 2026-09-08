<?php
namespace App\Modules\Identity\Http;
use App\Modules\Identity\Application\{Permissions, Totp};
use App\Contracts\Module\{AuditSink, TenantDirectory};
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\Rule;
class RoleRolloutController
{
    public function __construct(private DatabaseManager $db, private Hasher $hash) {}
    private function authorize(Request $r): void
    {
        abort_unless($r->user()->role === 'system_admin', 403);
    }
    public function catalog(Request $r)
    {
        $this->authorize($r);
        return ['permissions' => Permissions::catalog()];
    }
    public function preview(Request $r, TenantDirectory $tenants)
    {
        $this->authorize($r);
        $d = $r->validate([
            'name' => 'required|string|max:120',
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(Permissions::catalog()))],
            'tenant_ids' => 'required|array|min:1|max:50',
            'tenant_ids.*' => 'integer|distinct',
        ]);
        abort_if(
            count(
                array_intersect($d['permissions'], [
                    'reservation.write',
                    'reservation.cancel',
                    'reservation.export',
                ]),
            ) && !in_array('reservation.read', $d['permissions'], true),
            422,
            'Reservierungs-Lesezugriff fehlt.',
        );
        abort_if(
            in_array('waitlist.write', $d['permissions'], true) &&
                !in_array('waitlist.read', $d['permissions'], true),
            422,
            'Wartelisten-Lesezugriff fehlt.',
        );
        foreach ($d['tenant_ids'] as $id) {
            abort_unless($tenants->exists((int) $id), 422, 'Restaurant existiert nicht.');
        }
        abort_if(
            $this->db
                ->table('restaurant_roles')
                ->whereIn('tenant_id', $d['tenant_ids'])
                ->where('name', $d['name'])
                ->exists(),
            409,
            'Eine gleichnamige Rolle existiert bereits. Bestehende Rollen werden nicht überschrieben.',
        );
        $token = bin2hex(random_bytes(24));
        $r->session()->put('restaurant_role_rollout', [
            'token' => $token,
            'data' => $d,
            'expires' => time() + 300,
            'user' => $r->user()->id,
        ]);
        return ['token' => $token, 'preview' => $d, 'expires_in' => 300];
    }
    public function apply(Request $r, AuditSink $audit)
    {
        $this->authorize($r);
        $d = $r->validate([
            'token' => 'required|string',
            'password' => 'required|string',
            'mfa_code' => 'required|string',
        ]);
        $preview = $r->session()->pull('restaurant_role_rollout');
        abort_unless(
            $preview &&
                $preview['expires'] >= time() &&
                $preview['user'] === $r->user()->id &&
                hash_equals($preview['token'], $d['token']),
            409,
            'Vorschau abgelaufen oder bereits verwendet. Bitte neu prüfen.',
        );
        return $this->db->transaction(function () use ($r, $d, $preview, $audit) {
            $user = \App\Modules\Identity\Domain\User::whereKey($r->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $user->active &&
                    $user->role === 'system_admin' &&
                    $this->hash->check($d['password'], $user->password) &&
                    $user->mfa_secret,
                403,
                'Kennwort und eingerichtete Zwei-Faktor-Anmeldung erforderlich.',
            );
            $step = Totp::verify($user->mfa_secret, $d['mfa_code'], $user->mfa_last_step);
            abort_if($step === false, 403, 'Bestätigungscode ungültig oder bereits benutzt.');
            $data = $preview['data'];
            $ids = array_map('intval', $data['tenant_ids']);
            sort($ids);
            // Platform-stored roles are created in one transaction. Never alter user assignments.
            $tenants = $this->db
                ->table('tenants')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($tenants->count() === count($ids), 409, 'Restaurantliste wurde geändert.');
            abort_if(
                $this->db
                    ->table('restaurant_roles')
                    ->whereIn('tenant_id', $ids)
                    ->where('name', $data['name'])
                    ->exists(),
                409,
                'Rollen wurden inzwischen geändert.',
            );
            $result = [];
            foreach ($ids as $id) {
                $role = $this->db->table('restaurant_roles')->insertGetId([
                    'tenant_id' => $id,
                    'name' => $data['name'],
                    'permissions' => json_encode($data['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $audit->record('role.rollout_created', $role, $id);
                $result[] = ['tenant_id' => $id, 'role_id' => $role];
            }
            $user->update(['mfa_last_step' => $step]);
            return ['created' => $result];
        });
    }
}
