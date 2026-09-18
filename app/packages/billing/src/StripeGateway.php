<?php
namespace App\Modules\Billing;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory;
class StripeGateway
{
    public function __construct(
        private DatabaseManager $db,
        private Encrypter $crypt,
        private Factory $http,
    ) {}
    public function settings(): object
    {
        return $this->db->table('billing_automation')->find(1);
    }
    public function secrets(): array
    {
        $s = $this->settings();
        return $s->secrets
            ? json_decode($this->crypt->decryptString($s->secrets), true, flags: JSON_THROW_ON_ERROR)
            : [];
    }
    public function request(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $s = $this->settings();
        $secrets = $this->secrets();
        abort_unless(
            $s->enabled && !empty($secrets['api_key']),
            422,
            'Zahlungsanbieter ist nicht eingerichtet.',
        );
        $http = $this->http
            ->asForm()
            ->withToken($secrets['api_key'])
            ->withHeaders(['Stripe-Version' => '2024-06-20'])
            ->connectTimeout(5)
            ->timeout(20)
            ->withoutRedirecting();
        if ($key) {
            $http = $http->withHeaders(['Idempotency-Key' => $key]);
        }
        try {
            $response = $http->send(
                $method,
                'https://api.stripe.com/v1/' . $path,
                $method === 'GET' ? ['query' => $data] : ['form_params' => $data],
            );
        } catch (\Throwable $e) {
            abort(502, 'Zahlungsanbieter nicht erreichbar. Erneut versuchen.');
        }
        abort_unless(
            $response->successful(),
            502,
            'Zahlungsanbieter hat die Anfrage abgelehnt. Konfiguration und Anbieterprotokoll prüfen.',
        );
        $result = $response->json();
        abort_unless(is_array($result), 502, 'Ungültige Anbieterantwort.');
        if (array_key_exists('livemode', $result)) {
            abort_unless(
                (bool) $result['livemode'] === (bool) $s->live,
                409,
                'Test- und Echtbetrieb stimmen nicht überein.',
            );
        }
        return $result;
    }
    public function verify(string $body, string $header): array
    {
        $secret = $this->secrets()['webhook_secret'] ?? '';
        abort_unless($this->settings()->enabled && $secret !== '', 503);
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            }
            if ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        abort_unless($timestamp && abs(time() - $timestamp) <= 300, 400, 'Ungültige Webhook-Signatur.');
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        abort_unless(
            count(array_filter($signatures, fn($s) => hash_equals($expected, $s))) > 0,
            400,
            'Ungültige Webhook-Signatur.',
        );
        try {
            $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            abort(400);
        }
        abort_unless(
            is_array($event) && ($event['api_version'] ?? '') === '2024-06-20',
            400,
            'Webhook auf API-Version 2024-06-20 einstellen.',
        );
        abort_unless(($event['livemode'] ?? null) === (bool) $this->settings()->live, 400);
        return $event;
    }
}
