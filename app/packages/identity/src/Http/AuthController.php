<?php
namespace App\Modules\Identity\Http;
use App\Modules\Identity\Domain\User;
use App\Contracts\Module\{AuditSink, ModuleAccess};
use App\Modules\Identity\Application\Totp;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hash,
        private PasswordBroker $passwords,
        private AuditSink $audit,
        private ModuleAccess $modules,
    ) {}
    public function csrf()
    {
        return response()->json(['token' => csrf_token()]);
    }
    public function status()
    {
        return [
            'bootstrapped' => (bool) $this->db->table('bootstrap_state')->where('id', 1)->value('completed'),
        ];
    }
    public function bootstrap(Request $r)
    {
        $expected = config('platzhirsch.bootstrap_token_hash');
        abort_unless(
            is_string($expected) &&
                strlen($expected) === 64 &&
                hash_equals($expected, hash('sha256', (string) $r->header('X-Setup-Token'))),
            403,
            'Einrichtungsschlüssel fehlt oder ist ungültig.',
        );
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        return $this->db->transaction(function () use ($data) {
            $state = $this->db->table('bootstrap_state')->where('id', 1)->lockForUpdate()->first();
            abort_if(
                $state->completed || User::where('role', 'system_admin')->exists(),
                409,
                'Einrichtung bereits abgeschlossen.',
            );
            $user = User::create([...$data, 'email' => strtolower($data['email']), 'role' => 'system_admin']);
            $this->db
                ->table('bootstrap_state')
                ->where('id', 1)
                ->update(['completed' => true]);
            $this->audit->record('bootstrap.completed', $user->id);
            return response()->json(['user' => $user], 201);
        });
    }
    public function login(Request $r)
    {
        $data = $r->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'mfa_code' => 'nullable|string',
        ]);
        $user = User::where('email', strtolower($data['email']))->first();
        // Perform a hash check for unknown accounts as well.
        $hash = $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid = $this->hash->check($data['password'], $hash);
        abort_unless($user && $valid && $user->active, 401, 'Anmeldung fehlgeschlagen.');
        $portal = $r->attributes->get('portal');
        abort_if(
            ($portal === 'administration' && !$user->isSystem()) ||
                ($portal === 'restaurant' && ($user->isSystem() || !$user->tenant_id)),
            401,
            'Anmeldung fehlgeschlagen.',
        );
        if ($user->mfa_secret) {
            if (empty($data['mfa_code'])) {
                return ['mfa_required' => true];
            }
            $this->db->transaction(function () use ($user, $data) {
                $locked = User::lockForUpdate()->findOrFail($user->id);
                $step = Totp::verify($locked->mfa_secret, $data['mfa_code'], $locked->mfa_last_step);
                abort_if($step === false, 401, 'Bestätigungscode ungültig oder bereits benutzt.');
                $locked->update(['mfa_last_step' => $step]);
            });
        }
        auth()->login($user);
        $r->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        $this->audit->record('auth.login', $user->id, $user->tenant_id);
        return ['user' => $this->identity($user)];
    }
    public function me(Request $r)
    {
        abort_unless($r->user()->active, 403);
        return $this->identity($r->user());
    }
    public function profile(Request $r)
    {
        abort_unless($r->user()->active, 403);
        $r->merge(['email' => strtolower((string) $r->input('email'))]);
        $d = $r->validate([
            'name' => 'required|string|max:120',
            'email' => [
                'required',
                'email',
                'max:254',
                \Illuminate\Validation\Rule::unique('users')->ignore($r->user()->id),
            ],
            'current_password' => 'required|string',
            'password' => ['nullable', 'confirmed', PasswordRule::min(12)],
            'mfa_code' => 'nullable|string',
        ]);
        return $this->db->transaction(function () use ($r, $d) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $user->active && $this->hash->check($d['current_password'], $user->password),
                403,
                'Aktuelles Kennwort ungültig.',
            );
            if ($user->mfa_secret) {
                $step = Totp::verify($user->mfa_secret, $d['mfa_code'] ?? '', $user->mfa_last_step);
                abort_if($step === false, 403, 'Aktueller Zwei-Faktor-Code erforderlich.');
                $user->mfa_last_step = $step;
            }
            $user->name = $d['name'];
            $user->email = strtolower($d['email']);
            if (!empty($d['password'])) {
                $user->password = $d['password'];
                $user->setRememberToken(bin2hex(random_bytes(30)));
                $this->db
                    ->table('sessions')
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $r->session()->getId())
                    ->delete();
            }
            $user->save();
            $r->session()->regenerate();
            $this->audit->record('auth.profile_updated', $user->id, $user->tenant_id);
            return $this->identity($user);
        });
    }
    private function identity(User $u): array
    {
        return [
            ...$u->toArray(),
            'mfa_enabled' => (bool) $u->mfa_secret,
            'session_lifetime_seconds' => (int) config('session.lifetime') * 60,
            'scopes' => $u->isSystem() ? ['system'] : ['customer'],
            'permissions' => $u->permissions(),
            'enabled_modules' => $u->tenant_id ? $this->modules->enabled($u->tenant_id) : [],
            'installed_modules' => $u->isSystem()
                ? array_column(app(\App\Core\Module\ModuleRegistry::class)->catalog(), 'code')
                : [],
        ];
    }
    public function logout(Request $r)
    {
        $this->audit->record('auth.logout');
        auth()->logout();
        if ($r->attributes->get('portal')) {
            $r->session()->forget('mfa_pending_' . $r->attributes->get('portal'));
            $r->session()->regenerate();
        } else {
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }
        return response()->noContent();
    }
    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        if (config('mail.default') !== 'log') {
            $this->passwords->sendResetLink(['email' => strtolower($r->input('email'))]);
        }
        return ['message' => 'Falls das Konto existiert, wurde ein Link angefordert.'];
    }
    public function reset(Request $r)
    {
        $data = $r->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $status = $this->passwords->reset($data, function (User $u, string $password) {
            $u->password = $password;
            $u->setRememberToken(bin2hex(random_bytes(30)));
            $u->save();
            $this->db->table('sessions')->where('user_id', $u->id)->delete();
        });
        abort_unless($status === PasswordBroker::PASSWORD_RESET, 422, 'Link ungültig oder abgelaufen.');
        return ['message' => 'Passwort geändert.'];
    }
    public function beginMfa(Request $r)
    {
        $r->validate(['password' => 'required|string']);
        abort_unless($this->hash->check($r->input('password'), $r->user()->password), 403);
        abort_if($r->user()->mfa_secret, 409, 'MFA ist bereits aktiv.');
        $secret = Totp::secret();
        $r->session()->put('mfa_pending_' . ($r->attributes->get('portal') ?? 'web'), [
            'secret' => $secret,
            'expires' => time() + 600,
        ]);
        return [
            'secret' => $secret,
            'uri' =>
                'otpauth://totp/' .
                rawurlencode('Platzhirsch:' . $r->user()->email) .
                '?secret=' .
                $secret .
                '&issuer=Platzhirsch&digits=6&period=30',
        ];
    }
    public function confirmMfa(Request $r)
    {
        $r->validate(['code' => 'required|string']);
        $pending = $r->session()->get('mfa_pending_' . ($r->attributes->get('portal') ?? 'web'));
        abort_unless($pending && $pending['expires'] > time(), 422);
        $step = Totp::verify($pending['secret'], $r->input('code'));
        abort_if($step === false, 422, 'Code ungültig.');
        $r->user()->update(['mfa_secret' => $pending['secret'], 'mfa_last_step' => $step]);
        $r->session()->forget('mfa_pending_' . ($r->attributes->get('portal') ?? 'web'));
        $this->audit->record('auth.mfa_enabled');
        return ['message' => 'Zwei-Faktor-Anmeldung aktiviert.'];
    }
}
