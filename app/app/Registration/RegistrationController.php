<?php
namespace App\Registration;

use App\Contracts\Module\{AccountProvisioner, ProvisioningDispatcher};
use App\Models\{Tenant, User};
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Validation\ValidationException;

class RegistrationController
{
    public static function available(): bool
    {
        return (bool) config('registration.enabled') && config('mail.default') === 'smtp'
            && str_starts_with((string) config('app.url'), 'https://')
            && filter_var(config('registration.privacy_url'), FILTER_VALIDATE_URL)
            && filter_var(config('registration.imprint_url'), FILTER_VALIDATE_URL);
    }

    public function index()
    {
        return response()->view('registration.landing', ['available' => self::available()]);
    }

    public function submit(Request $r, WebsiteCheck $check)
    {
        abort_unless(self::available(), 503, 'Die Registrierung ist noch nicht freigeschaltet.');
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $v = $r->validate([
            'business_name' => 'required|string|max:120',
            'owner_name' => 'required|string|max:120',
            'website' => 'required|string|max:500',
            'email' => 'required|email|max:254',
            'privacy' => 'accepted',
            'fax_number' => 'nullable|string|max:0',
        ]);
        $result = $check->check($v['website'], $v['email']);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $created = DB::transaction(function () use ($v, $result, $hash) {
            if (User::where('email', $v['email'])->exists()) return false;
            // Expired, unconfirmed requests hold no tenant or account resources.
            DB::table('registration_requests')->where('email', $v['email'])
                ->whereNull('verified_at')->where('expires_at', '<=', now())->delete();
            return DB::table('registration_requests')->insertOrIgnore([
                'email' => $v['email'], 'business_name' => $v['business_name'],
                'owner_name' => $v['owner_name'], ...$result,
                'keywords' => json_encode($result['keywords'], JSON_THROW_ON_ERROR),
                'token_hash' => $hash, 'expires_at' => now()->addDay(),
                'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
        });
        if ($created) {
            try {
                $url = rtrim(config('app.url'), '/') . '/registrierung/bestaetigen/' . $token;
                Mail::to($v['email'])->send(new VerificationMail($url, $v['business_name']));
            } catch (\Throwable) {
                DB::table('registration_requests')->where('token_hash', $hash)->whereNull('verified_at')->delete();
                throw ValidationException::withMessages(['email' => 'Die Bestätigungsmail konnte nicht versendet werden. Bitte später erneut versuchen.']);
            }
        }
        return redirect('/registrierung')->with('registration_sent', true);
    }

    public function confirm(string $token)
    {
        $row = $this->pending($token)->first();
        return response()->view('registration.confirm', ['registration' => $row, 'token' => $token], $row ? 200 : 410);
    }

    public function complete(Request $r, string $token, AccountProvisioner $accounts, ProvisioningDispatcher $provisioning)
    {
        abort_unless(self::available(), 503, 'Die Registrierung ist derzeit pausiert.');
        $v = $r->validate(['password' => 'required|string|min:12|max:128|confirmed']);
        DB::transaction(function () use ($token, $v, $accounts, $provisioning) {
            $row = $this->pending($token)->lockForUpdate()->first();
            abort_unless($row, 410, 'Der Bestätigungslink ist abgelaufen oder wurde bereits verwendet.');
            if (User::where('email', $row->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Ein Zugang besteht bereits. Bitte die Passwort-zurücksetzen-Funktion im Restaurantlogin verwenden.']);
            }
            $suffix = bin2hex(random_bytes(12));
            $tenant = Tenant::create([
                'name' => $row->business_name, 'email' => $row->email, 'website' => $row->website,
                'timezone' => 'Europe/Berlin', 'status' => 'provisioning',
                'database_name' => 'ph_t_' . $suffix, 'database_user' => 'phu_' . $suffix,
                'database_password' => bin2hex(random_bytes(32)),
            ]);
            $accounts->createOwner([
                'name' => $row->owner_name, 'email' => $row->email, 'password' => $v['password'],
                'tenant_id' => $tenant->id,
            ]);
            DB::table('registration_requests')->where('id', $row->id)->update([
                'verified_at' => now(), 'token_hash' => null, 'tenant_id' => $tenant->id, 'updated_at' => now(),
            ]);
            $provisioning->create($tenant->id);
            Audit::record('registration.confirmed', $row->id, $tenant->id);
        });
        return redirect('/registrierung')->with('registration_completed', true);
    }

    private function pending(string $token)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        return DB::table('registration_requests')->where('token_hash', hash('sha256', $token))
            ->whereNull('verified_at')->where('expires_at', '>', now());
    }
}
