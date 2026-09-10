<?php
namespace App\Modules\Identity\Http;
use App\Modules\Identity\Application\{OpenIdProvider, Totp};
use App\Modules\Identity\Domain\User;
use App\Contracts\Module\AuditSink;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
class SsoController
{
    public function __construct(
        private OpenIdProvider $provider,
        private DatabaseManager $db,
        private Hasher $hash,
        private AuditSink $audit,
    ) {}
    private function portal(Request $r): string
    {
        $p = $r->attributes->get('portal');
        abort_unless(in_array($p, ['administration', 'restaurant'], true), 400, 'Portal fehlt.');
        return $p;
    }
    private function fits(User $u, string $p): bool
    {
        return $u->active &&
            ($p === 'administration' ? $u->isSystem() : !$u->isSystem() && (bool) $u->tenant_id);
    }
    public function status(Request $r)
    {
        $p = $this->portal($r);
        $pending = $r->session()->get('sso.mfa.' . $p);
        return [
            'enabled' => $this->provider->ready(),
            'label' => config('sso.label'),
            'pending_mfa' => $pending && $pending['expires'] >= time(),
            'linked' => $r->user()
                ? (bool) $this->db
                    ->table('identity_sso_accounts')
                    ->where('user_id', $r->user()->id)
                    ->where('provider', $this->provider->key())
                    ->exists()
                : false,
            'notice' => $r->session()->pull('sso.notice.' . $p),
        ];
    }
    private function reauthenticate(Request $r): User
    {
        $d = $r->validate(['password' => 'required|string', 'mfa_code' => 'nullable|string']);
        return $this->db->transaction(function () use ($r, $d) {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $u->active && $this->hash->check($d['password'], $u->password),
                403,
                'Bestätigung fehlgeschlagen.',
            );
            if ($u->mfa_secret) {
                $step = Totp::verify($u->mfa_secret, $d['mfa_code'] ?? '', $u->mfa_last_step);
                abort_if($step === false, 403, 'Frischer Zwei-Faktor-Code erforderlich.');
                $u->update(['mfa_last_step' => $step]);
            }
            return $u;
        });
    }
    public function start(Request $r)
    {
        return $this->begin($r, null);
    }
    public function link(Request $r)
    {
        return $this->begin($r, $this->reauthenticate($r)->id);
    }
    private function begin(Request $r, ?int $link): array
    {
        $p = $this->portal($r);
        abort_unless($this->provider->ready(), 503, 'SSO ist nicht eingerichtet.');
        if ($link) {
            abort_unless($this->fits($r->user(), $p), 403);
        }
        $r->session()->regenerate();
        $r->session()->forget('sso.mfa.' . $p);
        $flow = [
            'state' => bin2hex(random_bytes(32)),
            'nonce' => bin2hex(random_bytes(32)),
            'verifier' => OpenIdProvider::encode(random_bytes(48)),
            'expires' => time() + 300,
            'provider' => $this->provider->key(),
            'link' => $link,
        ];
        $r->session()->put('sso.flow.' . $p, $flow);
        return ['url' => $this->provider->authorization($p, $flow)];
    }
    public function callback(Request $r, string $portal)
    {
        $p = $this->portal($r);
        abort_unless($p === $portal, 400);
        $flow = $r->session()->pull('sso.flow.' . $p);
        try {
            if (
                !$this->provider->ready() ||
                !$flow ||
                $flow['expires'] < time() ||
                $flow['provider'] !== $this->provider->key() ||
                !is_string($r->query('state')) ||
                !hash_equals($flow['state'], $r->query('state')) ||
                !is_string($r->query('code')) ||
                strlen($r->query('code')) > 8192 ||
                $r->has('error')
            ) {
                throw new \RuntimeException('invalid_flow');
            }
            $subject = $this->provider->subject($r->query('code'), $p, $flow);
            $digest = hash('sha256', $subject);
            if ($flow['link']) {
                abort_unless(
                    $r->user() && $r->user()->id === $flow['link'] && $this->fits($r->user(), $p),
                    403,
                );
                $this->db->transaction(function () use ($r, $flow, $digest) {
                    $user = User::whereKey($flow['link'])->lockForUpdate()->firstOrFail();
                    abort_unless($user->active, 403);
                    $existing = $this->db
                        ->table('identity_sso_accounts')
                        ->where('provider', $flow['provider'])
                        ->where('subject_hash', $digest)
                        ->first();
                    abort_if($existing && (int) $existing->user_id !== (int) $user->id, 409);
                    $this->db
                        ->table('identity_sso_accounts')
                        ->updateOrInsert(
                            ['user_id' => $user->id, 'provider' => $flow['provider']],
                            ['subject_hash' => $digest, 'created_at' => now(), 'updated_at' => now()],
                        );
                    $this->audit->record('auth.sso_linked', $user->id, $user->tenant_id);
                });
                return redirect('/' . $p . '/login#account');
            }
            $link = $this->db
                ->table('identity_sso_accounts')
                ->where('provider', $flow['provider'])
                ->where('subject_hash', $digest)
                ->first();
            $user = $link ? User::find($link->user_id) : null;
            if (!$user || !$this->fits($user, $p)) {
                throw new \RuntimeException('unlinked_account');
            }
            if ($user->mfa_secret) {
                $r->session()->put('sso.mfa.' . $p, [
                    'user' => $user->id,
                    'link' => $link->id,
                    'subject_hash' => $digest,
                    'provider' => $flow['provider'],
                    'expires' => time() + 300,
                ]);
            } else {
                $this->finish($r, $user);
            }
        } catch (\Throwable) {
            $r->session()->put(
                'sso.notice.' . $p,
                'SSO-Anmeldung nicht abgeschlossen. Bitte erneut starten. Ein bestehendes Konto muss zuerst unter „Mein Konto“ mit SSO verknüpft werden.',
            );
        }
        return redirect('/' . $p . '/login');
    }
    private function finish(Request $r, User $user): void
    {
        abort_unless($this->fits($user->fresh(), $this->portal($r)), 403);
        auth()->login($user);
        $r->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        $this->audit->record('auth.sso_login', $user->id, $user->tenant_id);
    }
    public function mfa(Request $r)
    {
        $p = $this->portal($r);
        $d = $r->validate(['mfa_code' => 'required|string']);
        $pending = $r->session()->pull('sso.mfa.' . $p);
        abort_unless(
            $this->provider->ready() &&
                $pending &&
                $pending['expires'] >= time() &&
                $pending['provider'] === $this->provider->key(),
            403,
            'SSO-Anmeldung bitte neu starten.',
        );
        return $this->db->transaction(function () use ($r, $d, $pending, $p) {
            $user = User::whereKey($pending['user'])->lockForUpdate()->firstOrFail();
            abort_unless(
                $this->fits($user, $p) &&
                    $user->mfa_secret &&
                    $this->db
                        ->table('identity_sso_accounts')
                        ->where('id', $pending['link'])
                        ->where('subject_hash', $pending['subject_hash'])
                        ->where('user_id', $user->id)
                        ->where('provider', $pending['provider'])
                        ->exists(),
                403,
            );
            $step = Totp::verify($user->mfa_secret, $d['mfa_code'], $user->mfa_last_step);
            abort_if($step === false, 403, 'Code ungültig; SSO-Anmeldung bitte neu starten.');
            $user->update(['mfa_last_step' => $step]);
            $this->finish($r, $user);
            return ['ok' => true];
        });
    }
    public function unlink(Request $r)
    {
        $u = $this->reauthenticate($r);
        $this->db
            ->table('identity_sso_accounts')
            ->where('user_id', $u->id)
            ->where('provider', $this->provider->key())
            ->delete();
        $this->audit->record('auth.sso_unlinked', $u->id, $u->tenant_id);
        return ['ok' => true];
    }
}
