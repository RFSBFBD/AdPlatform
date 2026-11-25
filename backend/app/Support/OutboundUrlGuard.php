<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Accepts only public HTTPS endpoints for user-supplied base URLs.
 * Rejects credentials in the URL, custom ports, localhost and any host that
 * resolves to a private, loopback, link-local or reserved address.
 * assertPublicHttps() returns the resolved host and an IP to pin for the request,
 * so the connection cannot be redirected to a different address after the check.
 */
final class OutboundUrlGuard
{
    /** @return array{host:string,ip:string} */
    public static function assertPublicHttps(string $url): array
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            throw new InvalidArgumentException('The endpoint must be an https URL.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new InvalidArgumentException('The endpoint must not contain credentials.');
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            throw new InvalidArgumentException('Only the default https port is allowed.');
        }
        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('The endpoint host is not allowed.');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
                if (! empty($r['ip'])) $ips[] = $r['ip'];
            }
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            throw new InvalidArgumentException('The endpoint host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('The endpoint host is not allowed.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $l = strtolower($ip);
            // unique-local fc00::/7, link-local fe80::/10 and the unspecified/loopback addresses
            if (preg_match('/^(fc|fd|fe[89ab])/', $l) || $l === '::' || $l === '::1') return false;
        } else {
            // carrier-grade NAT 100.64.0.0/10 and link-local 169.254.0.0/16
            $n = ip2long($ip);
            if ($n !== false && (($n & 0xFFC00000) === 0x64400000 || ($n & 0xFFFF0000) === 0xA9FE0000)) return false;
        }

        return true;
    }
}
