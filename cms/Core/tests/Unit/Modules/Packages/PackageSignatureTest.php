<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Build a valid manifest array for the given key id; tests mutate it to probe the schema.
 *
 * @return array<string, mixed>
 */
function pkgManifest(string $keyId, array $overrides = []): array
{
    return array_merge([
        'format' => 1,
        'name' => 'Demo',
        'version' => '2026.03.0',
        'zip_sha256' => hash('sha256', 'zip'),
        'zip_size' => 1234,
        'unpacked_size' => 5678,
        'files' => [
            'Demo/module.json' => hash('sha256', 'a'),
            'Demo/app/Providers/DemoServiceProvider.php' => hash('sha256', 'b'),
        ],
        'requires' => ['Core' => '2026.01.0'],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => $keyId,
    ], $overrides);
}

/**
 * Encode a manifest exactly as a build would.
 */
function pkgBytes(array $manifest): string
{
    return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Fresh test keypair: [secret, publicB64, keyId].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function pkgKeypair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);

    return [sodium_crypto_sign_secretkey($pair), base64_encode($public), KeyId::fromPublicKey($public)];
}

/**
 * Run a callable and return the PackageVerificationException error code it threw (or null).
 */
function pkgErrorCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

/**
 * Rewrite one envelope field and return the new sig.json.
 */
function pkgReplaceEnvelope(string $sigJson, string $field, string $value): string
{
    $doc = json_decode($sigJson, true, 512, JSON_THROW_ON_ERROR);
    $doc[$field] = $value;

    return json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('derives a 16-hex key id from the public key', function () {
    [, $publicB64, $keyId] = pkgKeypair();

    expect($keyId)->toMatch('/^[0-9a-f]{16}$/')
        ->and($keyId)->toBe(substr(hash('sha256', base64_decode($publicB64)), 0, 16));
});

it('verifies a valid signature and returns the manifest', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $bytes = pkgBytes(pkgManifest($keyId));
    $sigJson = (new PackageSigner())->envelope($bytes, $secret);

    $manifest = (new PackageVerifier([$keyId => $publicB64], []))->verify($sigJson);

    expect($manifest)->toBeInstanceOf(ReleaseManifest::class)
        ->and($manifest->name)->toBe('Demo')
        ->and($manifest->version)->toBe('2026.03.0')
        ->and($manifest->keyId)->toBe($keyId)
        ->and($manifest->files)->toHaveCount(2)
        ->and($manifest->toArray())->toBe(pkgManifest($keyId));
});

it('returns key id and base64 signature from sign()', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $result = (new PackageSigner())->sign('payload', $secret);

    expect($result['key_id'])->toBe($keyId)
        ->and(sodium_crypto_sign_verify_detached(base64_decode($result['signature'], true), 'payload', base64_decode($publicB64)))->toBeTrue();
});

it('rejects one flipped byte in the manifest at the signature step', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $bytes = pkgBytes(pkgManifest($keyId));
    $sigJson = (new PackageSigner())->envelope($bytes, $secret);
    $tampered = $bytes;
    $tampered[10] = chr(ord($tampered[10]) ^ 0x01);

    $code = pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))
        ->verify(pkgReplaceEnvelope($sigJson, 'manifest', base64_encode($tampered))));

    expect($code)->toBe('MODULE_PACKAGE_SIGNATURE');
});

it('rejects one flipped byte in the signature', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);
    $sig = base64_decode(json_decode($sigJson, true)['signature'], true);
    $sig[5] = chr(ord($sig[5]) ^ 0x80);

    $code = pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))
        ->verify(pkgReplaceEnvelope($sigJson, 'signature', base64_encode($sig))));

    expect($code)->toBe('MODULE_PACKAGE_SIGNATURE');
});

it('fails a tampered non-JSON manifest at the signature step, before any manifest parsing', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);
    $garbage = '{not json at all';

    // The schema step alone would reject these bytes with a schema code ...
    expect(pkgErrorCode(fn () => ReleaseManifest::fromVerifiedBytes($garbage)))->toBe('MODULE_PACKAGE_SCHEMA');

    // ... but the verifier stops earlier, at the signature.
    $code = pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))
        ->verify(pkgReplaceEnvelope($sigJson, 'manifest', base64_encode($garbage))));

    expect($code)->toBe('MODULE_PACKAGE_SIGNATURE');
});

it('rejects an unknown key id', function () {
    [$secret, , $keyId] = pkgKeypair();
    [, $otherPublic, $otherId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);

    expect(pkgErrorCode(fn () => (new PackageVerifier([$otherId => $otherPublic], []))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_KEY_UNKNOWN');
});

it('rejects a revoked key id even when it is still trusted', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);

    expect(pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], [$keyId]))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_KEY_REVOKED');
});

it('rejects a configured public key that does not hash to its key id', function () {
    [$secret, , $keyId] = pkgKeypair();
    [, $otherPublic] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);

    expect(pkgErrorCode(fn () => (new PackageVerifier([$keyId => $otherPublic], []))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_KEY_INVALID');
});

it('rejects a manifest whose key_id differs from the signing key', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest('0123456789abcdef')), $secret);

    expect(pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_KEY_MISMATCH');
});

it('rejects a validly signed manifest with an unknown field at the schema step', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $bytes = pkgBytes(pkgManifest($keyId, ['extra' => true]));
    $sigJson = (new PackageSigner())->envelope($bytes, $secret);

    expect(pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_SCHEMA');
});

it('rejects a malformed envelope', function (string $sigJson) {
    [, $publicB64, $keyId] = pkgKeypair();

    expect(pkgErrorCode(fn () => (new PackageVerifier([$keyId => $publicB64], []))->verify($sigJson)))
        ->toBe('MODULE_PACKAGE_ENVELOPE');
})->with([
    'not json' => ['nope'],
    'missing manifest' => ['{"key_id":"0123456789abcdef","signature":"AA=="}'],
    'extra key' => ['{"key_id":"0123456789abcdef","signature":"AA==","manifest":"AA==","x":"y"}'],
    'non-string' => ['{"key_id":"0123456789abcdef","signature":1,"manifest":"AA=="}'],
    'bad base64' => ['{"key_id":"0123456789abcdef","signature":"!!!","manifest":"AA=="}'],
    'bad key id' => ['{"key_id":"XYZ","signature":"AA==","manifest":"AA=="}'],
]);

it('rejects a format other than 1 with the format code', function () {
    expect(pkgErrorCode(fn () => ReleaseManifest::fromVerifiedBytes(pkgBytes(pkgManifest('0123456789abcdef', ['format' => 2])))))
        ->toBe('MODULE_PACKAGE_FORMAT');
});

it('rejects schema violations', function (array $overrides, ?string $drop = null) {
    $manifest = pkgManifest('0123456789abcdef', $overrides);
    if ($drop !== null) {
        unset($manifest[$drop]);
    }

    expect(pkgErrorCode(fn () => ReleaseManifest::fromVerifiedBytes(pkgBytes($manifest))))->toBe('MODULE_PACKAGE_SCHEMA');
})->with([
    'missing field' => [[], 'php_min'],
    'lowercase name' => [['name' => 'demo']],
    'name with dash' => [['name' => 'De-mo']],
    'one-letter name' => [['name' => 'D']],
    'bad version' => [['version' => '2026.03']],
    'version with suffix' => [['version' => '2026.03.0-beta']],
    'uppercase hash' => [['zip_sha256' => strtoupper(hash('sha256', 'zip'))]],
    'short hash' => [['zip_sha256' => 'abc']],
    'negative size' => [['zip_size' => -1]],
    'string size' => [['unpacked_size' => '10']],
    'string bool' => [['custom_code' => 'false']],
    'bad key id' => [['key_id' => 'ABCDEF0123456789']],
    'bad built_at' => [['built_at' => 'yesterday']],
    'bad php_min' => [['php_min' => 'eight']],
    'empty files' => [['files' => []]],
    'file hash uppercase' => [['files' => ['Demo/a.php' => strtoupper(hash('sha256', 'a'))]]],
    'path outside module' => [['files' => ['Other/a.php' => hash('sha256', 'a')]]],
    'leading slash' => [['files' => ['/Demo/a.php' => hash('sha256', 'a')]]],
    'backslash' => [['files' => ['Demo\\a.php' => hash('sha256', 'a')]]],
    'dot dot' => [['files' => ['Demo/../etc/passwd' => hash('sha256', 'a')]]],
    'dot segment' => [['files' => ['Demo/./a.php' => hash('sha256', 'a')]]],
    'empty segment' => [['files' => ['Demo//a.php' => hash('sha256', 'a')]]],
    'drive letter' => [['files' => ['C:/Demo/a.php' => hash('sha256', 'a')]]],
    'nul byte' => [['files' => ["Demo/a\0.php" => hash('sha256', 'a')]]],
    'too deep' => [['files' => ['Demo/' . implode('/', array_fill(0, 20, 'd')) => hash('sha256', 'a')]]],
    'case duplicate' => [['files' => ['Demo/A.php' => hash('sha256', 'a'), 'Demo/a.php' => hash('sha256', 'b')]]],
    'requires bad name' => [['requires' => ['core' => '2026.01.0']]],
    'requires list' => [['requires' => ['Core']]],
]);

it('rejects invalid JSON and a non-object document at the schema step', function (string $bytes) {
    expect(pkgErrorCode(fn () => ReleaseManifest::fromVerifiedBytes($bytes)))->toBe('MODULE_PACKAGE_SCHEMA');
})->with(['', 'null', '[]', '"x"', '{"format":1']);

it('never puts key material in exception messages', function () {
    [$secret, $publicB64, $keyId] = pkgKeypair();
    $sigJson = (new PackageSigner())->envelope(pkgBytes(pkgManifest($keyId)), $secret);
    $sig = json_decode($sigJson, true)['signature'];

    try {
        (new PackageVerifier([$keyId => $publicB64], [$keyId]))->verify($sigJson);
        $message = '';
    } catch (PackageVerificationException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')
        ->and($message)->not->toContain($publicB64)
        ->and($message)->not->toContain($sig)
        ->and($message)->not->toContain(base64_encode($secret));
});
