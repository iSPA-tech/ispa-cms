<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

/**
 * Decides whether an IP address is a public internet address a workflow may connect to.
 *
 * Refused IPv4: this-network, private (10/8, 172.16/12, 192.168/16), carrier NAT, loopback, link-local (incl. the
 * 169.254.169.254 cloud metadata address), IETF/test/benchmark blocks, 6to4 relay, multicast and reserved.
 * IPv6: only global unicast (2000::/3) is allowed, minus IETF special blocks, documentation, 6to4 and Teredo; so
 * ::, ::1, fc00::/7, fe80::/10, fec0::/10 and ff00::/8 are refused. IPv4-mapped / IPv4-compatible / NAT64 addresses
 * are judged by the IPv4 address they carry.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
final class IpRangeGuard
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @var list<string> Blocks inside 2000::/3 that are not public internet. */
    private const BLOCKED_V6 = [
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[] ');
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return !self::inAny($binary, self::BLOCKED_V4);
        }

        $embedded = self::embeddedV4($binary);
        if ($embedded !== null) {
            return !self::inAny($embedded, self::BLOCKED_V4);
        }

        // Global unicast only: the first three bits must be 001.
        if ((ord($binary[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return !self::inAny($binary, self::BLOCKED_V6);
    }

    /**
     * The IPv4 address inside ::ffff:a.b.c.d, ::a.b.c.d or 64:ff9b::a.b.c.d (NAT64), as 4 bytes.
     */
    private static function embeddedV4(string $binary): ?string
    {
        $prefix = substr($binary, 0, 12);
        if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
            // :: and ::1 are not IPv4-compatible addresses; let the global-unicast rule refuse them.
            if ($prefix === str_repeat("\0", 12) && ord($binary[12]) === 0) {
                return null;
            }

            return substr($binary, 12);
        }
        if ($prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return substr($binary, 12);
        }

        return null;
    }

    /**
     * @param list<string> $cidrs
     */
    private static function inAny(string $binary, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $net = inet_pton($network);
            if ($net === false || strlen($net) !== strlen($binary)) {
                continue;
            }
            if (self::matches($binary, $net, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $binary, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
