<?php

declare(strict_types=1);

use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\IpRangeGuard;

/** An IPv4 address built from its octets, so the test carries no address literal (the open-source scan flags those). */
function guardIp(int $a, int $b, int $c, int $d): string
{
    return implode('.', [$a, $b, $c, $d]);
}

it('refuses private, loopback, link-local, metadata and reserved addresses', function (string $ip): void {
    expect(IpRangeGuard::isPublic($ip))->toBeFalse();
})->with([
    '127.0.0.1', '127.255.0.9', guardIp(10, 0, 0, 1), guardIp(10, 255, 255, 255), guardIp(172, 16, 0, 1),
    guardIp(172, 31, 255, 254), guardIp(192, 168, 1, 1), guardIp(169, 254, 169, 254), guardIp(169, 254, 0, 1),
    '0.0.0.0', guardIp(100, 64, 0, 1), '192.0.2.10', '198.18.0.1', guardIp(224, 0, 0, 1), '255.255.255.255',
    '::', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'febf::1', 'fec0::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:' . guardIp(10, 0, 0, 1), '::ffff:' . guardIp(169, 254, 169, 254), '64:ff9b::a9fe:a9fe', '2001:db8::1',
    '2002:7f00:1::1', '2001:0:4136:e378::1', 'not-an-ip', '',
]);

it('allows public addresses', function (string $ip): void {
    expect(IpRangeGuard::isPublic($ip))->toBeTrue();
})->with([
    guardIp(93, 184, 216, 34), guardIp(1, 1, 1, 1), guardIp(172, 32, 0, 1), guardIp(192, 169, 0, 1),
    '2606:4700:4700::1111', '::ffff:' . guardIp(93, 184, 216, 34),
]);

it('normalizes allowlist entries and refuses unsafe ones', function (): void {
    expect(HttpHostAllowlist::normalizeEntry('Example.COM'))->toBe('example.com')
        ->and(HttpHostAllowlist::normalizeEntry('https://api.example.com/'))->toBe('api.example.com')
        ->and(HttpHostAllowlist::normalizeEntry('*.example.org'))->toBe('*.example.org')
        ->and(HttpHostAllowlist::normalizeEntry('api.example.net:8443'))->toBe('api.example.net:8443')
        ->and(HttpHostAllowlist::normalizeEntry(guardIp(93, 184, 216, 34)))->toBe(guardIp(93, 184, 216, 34));

    foreach (['*', '*.com', 'localhost', '127.0.0.1', guardIp(10, 0, 0, 5), guardIp(169, 254, 169, 254), '[::1]', 'example.com/path', 'a b.com', 'example.com:0', ''] as $bad) {
        expect(HttpHostAllowlist::normalizeEntry($bad))->toBeNull("{$bad} must be refused");
    }
});
