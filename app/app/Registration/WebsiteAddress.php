<?php
namespace App\Registration;

use Illuminate\Validation\ValidationException;

class WebsiteAddress
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = 'https://' . $value;
        }
        $p = parse_url($value);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || (isset($p['port']) && $p['port'] !== 443)) {
            $this->invalid();
        }
        $host = strtolower(rtrim($p['host'] ?? '', '.'));
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!$host || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/\.(local|localhost|internal|test|invalid|example)$/D', $host)) {
            $this->invalid();
        }
        $path = $p['path'] ?? '/';
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            $this->invalid();
        }
        $url = 'https://' . $host . ($path ?: '/');
        if (strlen($url) > 500) $this->invalid();
        return $url;
    }

    public function domain(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST)));
    }

    public function matchingEmail(string $url, string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $domain !== false && $domain === $this->domain($url);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['website' => 'Bitte eine öffentliche Website ohne Zugangsdaten oder URL-Parameter angeben. Die Prüfung erfolgt über HTTPS.']);
    }
}
