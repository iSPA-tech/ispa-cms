<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Security\PersonalDataIdentityService;

beforeEach(function () {
    config(['app.key' => 'base64:test-personal-data-key']);

    $this->service = new PersonalDataIdentityService();
});

test('normalizes email and Vietnam phone values', function () {
    expect($this->service->normalizeEmail('  Maria@Example.Test  '))->toBe('maria@example.test')
        ->and($this->service->normalizeEmail(''))->toBeNull()
        ->and($this->service->normalizePhone('+84 912 345 678'))->toBe('0912345678')
        ->and($this->service->normalizePhone('0912.345.678'))->toBe('0912345678')
        ->and($this->service->normalizePhone(null))->toBeNull();
});

test('creates stable contact hashes and privacy hints', function () {
    $firstPhoneHash = $this->service->contactHash('+84 912 345 678', 'phone');
    $secondPhoneHash = $this->service->contactHash('0912 345 678', 'phone');
    $emailHash = $this->service->contactHash('maria@example.test', 'email');

    expect($firstPhoneHash)->toBe($secondPhoneHash)
        ->and($firstPhoneHash)->toHaveLength(64)
        ->and($emailHash)->not->toBe($firstPhoneHash)
        ->and($this->service->contactHash('', 'email'))->toBeNull()
        ->and($this->service->contactHint('maria@example.test'))->toBe('ma***st')
        ->and($this->service->contactHint('1234'))->toBe('***');
});

test('builds customer fingerprints from normalized identity parts', function () {
    $emailHash = str_repeat('A', 64);
    $phoneHash = str_repeat('b', 64);

    $fingerprint = $this->service->customerFingerprint([
        'email_hash' => $emailHash,
        'phone_hash' => $phoneHash,
        'user_id' => 42,
    ]);

    expect($fingerprint)->toHaveLength(64)
        ->and($fingerprint)->toBe($this->service->customerFingerprint([
            'email_hash' => strtolower($emailHash),
            'phone_hash' => $phoneHash,
            'user_id' => 42,
        ]))
        ->and($this->service->customerFingerprint([]))->toBe('')
        ->and($this->service->customerFingerprint(['uuid' => 'abc-123']))->toHaveLength(64);
});

test('builds address fingerprints from normalized Vietnamese address fields', function () {
    $first = $this->service->addressFingerprint([
        'name' => 'Giáo xứ Tân Định',
        'phone' => '0912 345 678',
        'address_line1' => '12 Đường Trần Hưng Đạo',
        'city' => 'Thành phố Hồ Chí Minh',
        'country' => 'Việt Nam',
    ]);
    $second = $this->service->addressFingerprint([
        'name' => 'giáo xứ tân định',
        'phone' => '0912 345 678',
        'address_line1' => '12 duong tran hung dao',
        'city' => 'TP Ho Chi Minh',
        'country' => 'VN',
    ]);

    expect($first)->toHaveLength(64)
        ->and($first)->toBe($second)
        ->and($this->service->addressFingerprint([]))->toBe('');
});
