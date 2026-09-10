<?php
namespace App\Registration;

use GuzzleHttp\Psr7\{Uri, UriResolver};

class WebsiteClient
{
    public function __construct(private PublicDns $dns, private WebsiteAddress $address) {}

    public function html(string $url): string
    {
        $origin = $this->address->domain($url);
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $url = $this->address->normalize($url);
            if ($this->address->domain($url) !== $origin) {
                throw new \RuntimeException('website-domain-changed');
            }
            $host = parse_url($url, PHP_URL_HOST);
            $ip = $this->dns->addresses($host)[0];
            $ip = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $body = '';
            $location = null;
            $headersSize = 0;
            $curl = curl_init($url);
            try {
                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_PROXY => '',
                    CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'Platzhirsch-WebsiteCheck/1.0',
                    CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml'],
                    CURLOPT_ENCODING => '',
                    CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                        if (strlen($body) + strlen($chunk) > 524288) return 0;
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                    CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$location, &$headersSize) {
                        $headersSize += strlen($line);
                        if ($headersSize > 32768) return 0;
                        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                        return strlen($line);
                    },
                ]);
                $ok = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $type = strtolower(curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '');
                if ($ok === false) throw new \RuntimeException('website-unreachable');
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location) {
                    $next = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                    if (strtolower(parse_url($next, PHP_URL_SCHEME) ?? '') !== 'https') {
                        throw new \RuntimeException('website-insecure-redirect');
                    }
                    $url = $next;
                    continue;
                }
                if ($status !== 200 || !preg_match('~^(text/html|application/xhtml\+xml)(;|$)~', $type)) {
                    throw new \RuntimeException('website-not-html');
                }
                return $body;
            } finally {
                curl_close($curl);
            }
        }
        throw new \RuntimeException('website-too-many-redirects');
    }
}
