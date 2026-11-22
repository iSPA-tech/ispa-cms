<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Platform\PlatformSignature;

test('request signature is a deterministic v1 HMAC over the canonical string', function () {
    $secret = 'unit-secret-value';
    $body = '{"a":1}';
    $canonical = "v1\nPOST\n/api/platform/ping\na=1&b=2\n1700000000\nnonce-1\n".hash('sha256', $body);

    $signature = PlatformSignature::request($secret, 'post', 'api/platform/ping', 'a=1&b=2', 1700000000, 'nonce-1', $body);

    expect($signature)->toBe('v1='.hash_hmac('sha256', $canonical, $secret))
        ->and(PlatformSignature::request($secret, 'POST', '/api/platform/ping', 'a=1&b=2', 1700000000, 'nonce-1', $body))
        ->toBe($signature);
});

test('every signed request field changes the signature', function () {
    $base = ['s', 'POST', '/p', 'a=1', 1700000000, 'n', 'body'];
    $original = PlatformSignature::request(...$base);

    $variants = [
        ['other', 'POST', '/p', 'a=1', 1700000000, 'n', 'body'],
        ['s', 'GET', '/p', 'a=1', 1700000000, 'n', 'body'],
        ['s', 'POST', '/q', 'a=1', 1700000000, 'n', 'body'],
        ['s', 'POST', '/p', 'a=2', 1700000000, 'n', 'body'],
        ['s', 'POST', '/p', 'a=1', 1700000001, 'n', 'body'],
        ['s', 'POST', '/p', 'a=1', 1700000000, 'm', 'body'],
        ['s', 'POST', '/p', 'a=1', 1700000000, 'n', 'bodY'],
    ];

    foreach ($variants as $variant) {
        expect(PlatformSignature::request(...$variant))->not->toBe($original);
    }
});

test('canonical query sorts keys recursively and uses RFC 3986 encoding', function () {
    $query = PlatformSignature::canonicalQuery([
        'z' => 'a b',
        'a' => ['y' => 2, 'x' => 1],
        'm' => null,
    ]);

    expect($query)->toBe('a%5Bx%5D=1&a%5By%5D=2&z=a%20b')
        ->and(PlatformSignature::canonicalQuery([]))->toBe('')
        ->and(PlatformSignature::canonicalQuery(['b' => 1, 'a' => 2]))
        ->toBe(PlatformSignature::canonicalQuery(['a' => 2, 'b' => 1]));
});

test('response signature binds status, request nonce and body', function () {
    $signature = PlatformSignature::response('s', 200, 'n', '{"ok":true}');

    expect($signature)->toStartWith('v1=')
        ->and(PlatformSignature::response('s', 201, 'n', '{"ok":true}'))->not->toBe($signature)
        ->and(PlatformSignature::response('s', 200, 'm', '{"ok":true}'))->not->toBe($signature)
        ->and(PlatformSignature::response('s', 200, 'n', '{"ok":false}'))->not->toBe($signature)
        ->and(PlatformSignature::response('t', 200, 'n', '{"ok":true}'))->not->toBe($signature)
        ->and($signature)->not->toBe(PlatformSignature::request('s', 'GET', '/', '', 200, 'n', '{"ok":true}'));
});
