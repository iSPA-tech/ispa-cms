<?php

use Cms\Core\Services\StarterPacks\PackContentScanner;

uses(\Cms\Core\Tests\TestCase::class);

const PCS_FIXTURE = __DIR__ . '/../../Fixtures/starter-packs/scanner';

/** Scan one dirty fixture file and return its findings. */
function pcs_scan_dirty(string $file, array $settings = []): array
{
    return (new PackContentScanner())->scan(PCS_FIXTURE . '/dirty/' . $file, $settings);
}

/** Return [line, rule] pairs of findings, for compact assertions. */
function pcs_pairs(array $findings): array
{
    return array_map(static fn (array $f): array => [$f['line'], $f['rule']], $findings);
}

/** Scan an inline string and return the rule ids found. */
function pcs_rules(string $content, string $file = 'inline.txt'): array
{
    return array_values(array_unique(array_column((new PackContentScanner())->scanString($content, $file), 'rule')));
}

it('passes the clean fixture directory with no findings', function () {
    expect((new PackContentScanner())->scan(PCS_FIXTURE . '/clean'))->toBe([]);
});

it('reports private key blocks on the BEGIN line', function () {
    expect(pcs_pairs(pcs_scan_dirty('private-key.txt')))->toBe([[2, 'private_key'], [6, 'private_key']]);
    expect(pcs_rules("-----BEGIN RSA PRIVATE KEY-----\n"))->toBe(['private_key']);
    expect(pcs_rules("-----BEGIN EC PRIVATE KEY-----\n"))->toBe(['private_key']);
    expect(pcs_rules("-----BEGIN PGP PRIVATE KEY BLOCK-----\n"))->toBe(['private_key']);
    expect(pcs_rules("-----BEGIN PUBLIC KEY-----\n"))->toBe([]);
});

it('reports token-style values and api key assignments', function () {
    $findings = pcs_scan_dirty('tokens.env');

    expect(pcs_pairs($findings))->toContain([2, 'token'], [3, 'token'], [4, 'token'])
        ->and(array_column($findings, 'line'))->not->toContain(1);

    // Provider prefixes are assembled at runtime so the repository never holds a provider-shaped string.
    $fill = static fn (int $n): string => str_repeat('x', $n);
    foreach ([
        'sk_' . 'live_' . $fill(24),
        'pk_' . 'live_' . $fill(24),
        'gh' . 'p_' . $fill(36),
        'github_' . 'pat_' . $fill(40),
        'xo' . 'xb-' . 'fake-fake-fake',
        'AK' . 'IA' . 'FAKEFAKEFAKEFAKE',
        'AI' . 'za' . $fill(35),
    ] as $value) {
        expect(pcs_rules('value = "' . $value . '"'))->toContain('token');
    }

    expect(pcs_rules('"apiKey": "fakefakefake123"', 'a.json'))->toBe(['token']);
    expect(pcs_rules("'api-key' => 'fakefakefake123',", 'a.php'))->toBe(['token']);
});

it('reports non-placeholder values of secret-looking keys', function () {
    expect(pcs_pairs(pcs_scan_dirty('secret-values.json')))->toBe([
        [3, 'secret_value'],
        [4, 'secret_value'],
        [5, 'secret_value'],
        [6, 'secret_value'],
    ]);
    expect(pcs_rules('DB_PASSWORD=fake-pass', '.env'))->toBe(['secret_value']);
    expect(pcs_rules('"dsn": "mysql://user:fakepass@db.internal/app"', 'a.json'))->toContain('secret_value');
});

it('reports Laravel Crypt ciphertext values', function () {
    expect(pcs_pairs(pcs_scan_dirty('ciphertext.json')))->toBe([[3, 'ciphertext'], [4, 'ciphertext']]);
});

it('reports email addresses outside the reserved example domains', function () {
    expect(pcs_pairs(pcs_scan_dirty('emails.md')))->toBe([[5, 'email'], [6, 'email']]);
});

it('reports phone numbers', function () {
    expect(pcs_pairs(pcs_scan_dirty('phones.csv')))->toBe([[2, 'phone'], [3, 'phone'], [4, 'phone'], [5, 'phone']]);
    expect(pcs_rules('Gọi 0901234567 nhé'))->toBe(['phone']);
    expect(pcs_rules('Hotline: +84 28 3823 4567'))->toBe(['phone']);
});

it('reports secret setting keys from a setting definition list', function () {
    $settings = [
        ['setting_key' => 'mail_hub_relay_code', 'type' => 'text', 'input_setting' => '{"isPublic": false}'],
        ['setting_key' => 'maps_embed_code', 'type' => 'text', 'input_setting' => ['isPublic' => true]],
        ['setting_key' => 'site_name', 'type' => 'text', 'input_setting' => null],
    ];

    expect(pcs_pairs(pcs_scan_dirty('settings-leak.json', $settings)))->toBe([[4, 'secret_setting']]);
    expect(pcs_scan_dirty('settings-leak.json'))->toBe([]);
    expect((new PackContentScanner())->withSettings($settings)->scan(PCS_FIXTURE . '/dirty/settings-leak.json'))
        ->toBe([['file' => 'settings-leak.json', 'line' => 4, 'rule' => 'secret_setting']]);
    expect(pcs_rules("mail_hub_relay_code: abc\n", 'a.yml'))->toBe([]);
});

it('recurses into directories, skips binary files and reports relative paths with line numbers', function () {
    $findings = (new PackContentScanner())->scan(PCS_FIXTURE . '/dirty');
    $files = array_values(array_unique(array_column($findings, 'file')));

    expect($files)->toContain('nested/deeper/config.php', 'tokens.env', 'private-key.txt')
        ->and($files)->not->toContain('image.png')
        ->and($findings)->toContain(['file' => 'nested/deeper/config.php', 'line' => 5, 'rule' => 'secret_value']);

    foreach ($findings as $finding) {
        expect(array_keys($finding))->toBe(['file', 'line', 'rule']);
    }
});

it('never includes the matched value in findings', function () {
    $findings = (new PackContentScanner())->scan(PCS_FIXTURE . '/dirty');
    $encoded = json_encode($findings);

    expect($findings)->not->toBe([]);
    foreach (['Fake-Passw0rd', 'sk-test', 'gmail.com', '0987', 'eyJpdiI6', 'faketoken', 'fake-php-password', 'BEGIN'] as $needle) {
        expect($encoded)->not->toContain($needle);
    }
});

it('passes the documented false-positive allowances', function (string $content, string $file) {
    expect((new PackContentScanner())->scanString($content, $file))->toBe([]);
})->with([
    'long slug' => ['"slug": "huong-dan-cai-dat-tai-khoan-va-ket-noi-api-key-cho-nguoi-moi-bat-dau-2026"', 'a.json'],
    'uuid value' => ['"translation_group_uuid": "3f2b8c1e-9a4d-4e6f-8b21-0c9d7e5a4f10"', 'a.json'],
    'uuid of a token' => ['token_uuid: 0912a3b4-5678-4c9d-8e7f-091234567890', 'a.yml'],
    'prose' => ["Create an API key, then a token. Your password: keep it secret.\nBearer token auth is used.", 'a.md'],
    'max tokens yaml' => ['max_tokens: 4096', 'a.yml'],
    'max tokens json' => ['"max_tokens": 4096', 'a.json'],
    'password min length' => ['"password_min_length": 8', 'a.json'],
    'example emails' => ['info@example.com, support@example.test, a@mail.example.com', 'a.txt'],
    'dates and versions' => ['2026-11-08 2026-11-08T09:30:00+07:00 v1.2.3 1.0.0+20261108093000 ^13.0', 'a.txt'],
    'hex color and price' => ['color #0a7ea4 price 1.250.000 VND 12,500,000', 'a.txt'],
    'asset and scope' => ['logo@2x.png {\'@\'} @vue/runtime-core', 'a.html'],
    'translation label' => ['"password": "Mật khẩu"', 'a.json'],
    'placeholder' => ['API_KEY=${API_KEY}', '.env'],
]);
