<?php
namespace App\Registration;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicDns
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = array_values(array_unique(array_filter(array_map(
            fn($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: [],
        ))));
        if (!$ips || array_filter($ips, fn($ip) => !$this->isPublic($ip))) {
            throw new \RuntimeException('website-address-unavailable');
        }
        return $ips;
    }

    public function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !IpUtils::checkIp($ip, [
                '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15',
                '224.0.0.0/4', '240.0.0.0/4', '::ffff:0:0/96', '64:ff9b::/96',
                '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16',
                'fc00::/7', 'fe80::/10', 'ff00::/8',
            ]);
    }
}
