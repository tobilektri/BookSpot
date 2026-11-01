<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Which addresses the server may connect to on behalf of users (cover URLs,
 * push endpoints) and which proxies may name the client address.
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE alone let through IPv4
 * addresses wrapped in IPv6 (::ffff:127.0.0.1, 64:ff9b::7f00:1 …) and a few
 * special ranges (100.64/10 carrier NAT, 198.18/15 …) – a server request to
 * those can reach services inside the hoster's network (SSRF).
 */
final class NetworkGuard
{
    /** Not public, in addition to what PHP's filter flags reject. */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001::/32', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $embedded = self::embeddedIpv4($packed);
        if ($embedded !== null) {
            return self::isPublicIp($embedded);
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** IPv4 address carried in an IPv6 one: mapped/compatible, NAT64 (64:ff9b::/96), 6to4 (2002::/16). */
    private static function embeddedIpv4(string $packed): ?string
    {
        if (strlen($packed) !== 16) {
            return null;
        }
        $prefix12 = substr($packed, 0, 12);
        if ($prefix12 === str_repeat("\0", 10) . "\xff\xff" || $prefix12 === str_repeat("\0", 12)) {
            return inet_ntop(substr($packed, 12)) ?: null; // ::ffff:a.b.c.d, ::a.b.c.d
        }
        if ($prefix12 === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return inet_ntop(substr($packed, 12)) ?: null; // NAT64
        }
        if (substr($packed, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($packed, 2, 4)) ?: null; // 6to4
        }
        return null;
    }

    /** Does $ip lie in $cidr ("10.0.0.0/8", "fc00::/7" or a single address)? Families never match each other. */
    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton(trim($network));
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : max(0, min(strlen($ipBin) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @param list<string> $cidrs */
    public static function inAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($cidr !== '' && self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * All addresses of a host (A and AAAA). An IP literal is returned as is.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** Every address of the host is public (and there is at least one). */
    public static function isPublicHost(string $host): bool
    {
        $ips = self::resolve($host);
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return $ips !== [];
    }
}
