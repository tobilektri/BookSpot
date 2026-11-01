<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\Config;
use BookSpot\CoverService;
use BookSpot\NetworkGuard;
use BookSpot\PushService;
use BookSpot\Request;

final class NetworkGuardTest extends TestCase
{
    public function testOnlyPublicAddressesPass(): void
    {
        foreach ([
            '127.0.0.1', '10.1.2.3', '172.20.0.5', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1',
            '192.0.0.1', '198.18.0.1', '224.0.0.1', '255.255.255.255',
            '::1', '::', 'fe80::1', 'fd00::1', 'ff02::1', '2001:db8::1', '2001::1',
            // IPv4 wrapped in IPv6 – PHP's filter flags alone let these through
            '::ffff:127.0.0.1', '::ffff:7f00:1', '::127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::10.0.0.1', '2002:7f00:1::1',
            'not-an-ip',
        ] as $ip) {
            self::assertFalse(NetworkGuard::isPublicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '193.99.144.80', '2a00:1450:4001:80b::200e', '::ffff:8.8.8.8', '64:ff9b::808:808'] as $ip) {
            self::assertTrue(NetworkGuard::isPublicIp($ip), $ip);
        }
    }

    public function testCidr(): void
    {
        self::assertTrue(NetworkGuard::inCidr('172.18.0.3', '172.16.0.0/12'));
        self::assertFalse(NetworkGuard::inCidr('172.32.0.1', '172.16.0.0/12'));
        self::assertTrue(NetworkGuard::inCidr('10.0.0.5', '10.0.0.5'));
        self::assertTrue(NetworkGuard::inCidr('fd12::1', 'fc00::/7'));
        self::assertFalse(NetworkGuard::inCidr('10.0.0.5', 'fc00::/7'), 'families never match');
        self::assertFalse(NetworkGuard::inCidr('nope', '10.0.0.0/8'));
    }

    public function testCoverUrlsAndPushEndpointsMustBePublic(): void
    {
        self::assertNull(CoverService::publicTarget('http://[::ffff:127.0.0.1]:8080/x.png'));
        self::assertNull(CoverService::publicTarget('http://100.64.0.1/x.png'));
        self::assertSame('8.8.8.8', CoverService::publicTarget('https://8.8.8.8/x.png')['ip']);

        self::assertFalse(PushService::publicEndpoint('https://10.0.0.5/admin'));
        self::assertFalse(PushService::publicEndpoint('https://[::ffff:10.0.0.5]/push'));
        self::assertTrue(PushService::publicEndpoint('https://8.8.8.8/push/abc'));
    }

    public function testForwardedForOnlyFromTrustedProxies(): void
    {
        self::assertSame('172.18.0.2', Request::clientIp('172.18.0.2', '203.0.113.9'), 'no trusted proxies: header ignored');

        Config::set('TRUSTED_PROXIES', '172.16.0.0/12, 10.0.0.1');
        try {
            self::assertSame('198.51.100.7', Request::clientIp('172.18.0.2', '198.51.100.7'));
            self::assertSame('198.51.100.7', Request::clientIp('172.18.0.2', '6.6.6.6, 198.51.100.7, 10.0.0.1'), 'spoofed left part ignored');
            self::assertSame('8.8.4.4', Request::clientIp('8.8.4.4', '1.2.3.4'), 'direct visitors cannot claim another address');
            self::assertSame('172.18.0.2', Request::clientIp('172.18.0.2', 'garbage'));
        } finally {
            Config::set('TRUSTED_PROXIES', null);
        }
    }
}
