<?php
namespace App\Registration;

use Illuminate\Validation\ValidationException;

class WebsiteCheck
{
    public function __construct(private WebsiteAddress $address, private WebsiteClient $client) {}

    public function check(string $website, string $email): array
    {
        $url = $this->address->normalize($website);
        if (!$this->address->matchingEmail($url, $email)) {
            throw ValidationException::withMessages(['email' => 'Bitte eine E-Mail-Adresse der Website-Domain verwenden, z. B. kontakt@' . $this->address->domain($url) . '.']);
        }
        try {
            $html = $this->client->html($url);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['website' => 'Die Website konnte nicht sicher gelesen werden. Bitte HTTPS-Adresse und Erreichbarkeit prüfen und später erneut versuchen.']);
        }
        $html = preg_replace('~<!--.*?-->|<(script|style|noscript|template)\b[^>]*>.*?</\1>~is', ' ', $html);
        $text = mb_strtolower(html_entity_decode(preg_replace('~<[^>]*>~', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (config('registration.keywords') as $category => $groups) {
            $matches = [];
            foreach ($groups as $group => $keywords) {
                $matches[$group] = array_values(array_filter($keywords, fn($word) =>
                    preg_match('~(?<![\pL\pN])' . preg_quote($word, '~') . '(?![\pL\pN])~u', $text),
                ));
            }
            if ($matches['industry'] && $matches['offering']) {
                return ['website' => $url, 'domain' => $this->address->domain($url), 'category' => $category,
                    'keywords' => array_values(array_unique(array_merge(...array_values($matches))))];
            }
        }
        throw ValidationException::withMessages(['website' => 'Wir konnten keinen eindeutigen Restaurant- oder Hotelbezug erkennen. Bitte eine passende Seite Ihrer Website mit Speisekarte oder Zimmerangebot angeben.']);
    }
}
