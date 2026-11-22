<?php

declare(strict_types=1);

uses(Cms\Core\Tests\TestCase::class);

/*
 * `cms:module-package-sign --challenge=<text>` proves the publisher holds the private key of a public key
 * they register on the hub: it prints the base64 Ed25519 detached signature of the challenge text and nothing else.
 */

function signChallengeKeyFile(): array
{
    $dir = sys_get_temp_dir() . '/ispa-sign-challenge-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $pair = sodium_crypto_sign_keypair();
    $path = $dir . '/release-signing.key';
    file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
    chmod($path, 0600);

    return [$dir, $path, $pair];
}

test('the challenge signature verifies with the public key and no key material is printed', function () {
    [$dir, $path, $pair] = signChallengeKeyFile();
    $challenge = 'ispa-publisher-key-v1:11111111-2222-3333-4444-555555555555:abcdef0123456789:nonce-nonce-nonce-nonce-01';

    expect(\Illuminate\Support\Facades\Artisan::call('cms:module-package-sign', ['--challenge' => $challenge, '--key-file' => $path]))->toBe(0);
    $output = trim(\Illuminate\Support\Facades\Artisan::output());
    $signature = base64_decode(trim((string) last(preg_split('/\R/', $output))), true);

    expect($signature)->toBeString()
        ->and(sodium_crypto_sign_verify_detached((string) $signature, $challenge, sodium_crypto_sign_publickey($pair)))->toBeTrue()
        ->and($output)->not->toContain(base64_encode(sodium_crypto_sign_secretkey($pair)));

    unlink($path);
    rmdir($dir);
});

test('the challenge text must look like a hub key challenge', function () {
    [$dir, $path] = signChallengeKeyFile();

    $this->artisan('cms:module-package-sign', ['--challenge' => 'anything else', '--key-file' => $path])->assertFailed();

    unlink($path);
    rmdir($dir);
});
