<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Security\SensitiveDataService;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->service = new SensitiveDataService();
});

// --- wantsShowSensitive ---

test('wantsShowSensitive returns false when no param', function () {
    $request = Request::create('/test');

    expect($this->service->wantsShowSensitive($request))->toBeFalse();
});

test('wantsShowSensitive returns true for truthy values', function (mixed $value) {
    $request = Request::create('/test', 'GET', ['show_sensitive' => $value]);

    expect($this->service->wantsShowSensitive($request))->toBeTrue();
})->with([
    '1',
    'true',
    'TRUE',
    'yes',
    'on',
    1,
    true,
]);

test('wantsShowSensitive returns false for falsy values', function (mixed $value) {
    $request = Request::create('/test', 'GET', ['show_sensitive' => $value]);

    expect($this->service->wantsShowSensitive($request))->toBeFalse();
})->with([
    '0',
    'false',
    'no',
    0,
    false,
    '',
]);

test('wantsShowSensitive accepts legacy typo param', function () {
    $request = Request::create('/test', 'GET', ['show_senstive' => '1']);

    expect($this->service->wantsShowSensitive($request))->toBeTrue();
});

test('wantsShowSensitive prefers new param over legacy', function () {
    $request = Request::create('/test', 'GET', [
        'show_sensitive' => '0',
        'show_senstive' => '1',
    ]);

    expect($this->service->wantsShowSensitive($request))->toBeFalse();
});

// --- maskEmail ---

test('maskEmail handles null and empty', function () {
    expect($this->service->maskEmail(null))->toBeNull();
    expect($this->service->maskEmail(''))->toBe('');
});

test('maskEmail masks email correctly', function () {
    expect($this->service->maskEmail('john.doe@example.com'))->toBe('jo***@***.com');
    expect($this->service->maskEmail('a@b.com'))->toBe('a@***.com'); // single char kept as-is
    expect($this->service->maskEmail('test@sub.domain.org'))->toBe('te***@***.org');
});

test('maskEmail handles invalid email format', function () {
    expect($this->service->maskEmail('invalid-email'))->toBe('***@***.***');
});

// --- maskPhone ---

test('maskPhone handles null and empty', function () {
    expect($this->service->maskPhone(null))->toBeNull();
    expect($this->service->maskPhone(''))->toBe('');
});

test('maskPhone keeps last 4 digits', function () {
    expect($this->service->maskPhone('+1234567890'))->toBe('******7890');
    expect($this->service->maskPhone('0912345678'))->toBe('******5678');
    expect($this->service->maskPhone('123-456-7890'))->toBe('******7890');
    expect($this->service->maskPhone('(123) 456-7890'))->toBe('******7890');
});

test('maskPhone handles short numbers', function () {
    expect($this->service->maskPhone('1234'))->toBe('****');
    expect($this->service->maskPhone('123'))->toBe('***');
});

// --- maskAddress ---

test('maskAddress handles null and empty', function () {
    expect($this->service->maskAddress(null))->toBeNull();
    expect($this->service->maskAddress(''))->toBe('');
});

test('maskAddress keeps first N characters', function () {
    expect($this->service->maskAddress('123 Main Street, City, State'))->toBe('123 Main S***');
    expect($this->service->maskAddress('Short'))->toBe('Short'); // shorter than default keep
    expect($this->service->maskAddress('1234567890'))->toBe('1234567890'); // exactly keep length
    expect($this->service->maskAddress('12345678901'))->toBe('1234567890***'); // one more than keep
});

test('maskAddress respects custom keep length', function () {
    expect($this->service->maskAddress('123 Main Street', 5))->toBe('123 M***');
    expect($this->service->maskAddress('Short', 3))->toBe('Sho***');
});

// --- maskDob ---

test('maskDob handles null and empty', function () {
    expect($this->service->maskDob(null))->toBe('****');
    expect($this->service->maskDob(''))->toBe('****');
});

test('maskDob returns year from string date', function () {
    expect($this->service->maskDob('1990-05-15'))->toBe('1990');
    expect($this->service->maskDob('2001-12-25'))->toBe('2001');
    expect($this->service->maskDob('1985/03/10'))->toBe('1985');
});

test('maskDob returns year from DateTime', function () {
    $date = new \DateTime('1995-08-20');
    expect($this->service->maskDob($date))->toBe('1995');
});

test('maskDob returns year from Carbon', function () {
    $date = \Carbon\Carbon::parse('2000-01-01');
    expect($this->service->maskDob($date))->toBe('2000');
});

test('maskDob handles unparseable date', function () {
    expect($this->service->maskDob('not-a-date'))->toBe('****');
});

// --- apply ---

test('apply masks specified fields', function () {
    $data = [
        'email' => 'test@example.com',
        'secondary_email' => 'other@example.com',
        'name' => 'John Doe',
    ];

    $result = $this->service->apply($data, ['email', 'secondary_email'], [$this->service, 'maskEmail']);

    expect($result['email'])->toBe('te***@***.com');
    expect($result['secondary_email'])->toBe('ot***@***.com');
    expect($result['name'])->toBe('John Doe');
});

test('apply ignores non-existent fields', function () {
    $data = ['email' => 'test@example.com'];

    $result = $this->service->apply($data, ['email', 'phone'], [$this->service, 'maskEmail']);

    expect($result)->toBe(['email' => 'te***@***.com']);
});

// --- applyConfig ---

test('applyConfig masks multiple field types', function () {
    $data = [
        'email' => 'test@example.com',
        'phone' => '1234567890',
        'address' => '123 Main Street, City',
        'dob' => '1990-05-15',
        'name' => 'John Doe',
    ];

    $result = $this->service->applyConfig($data, [
        'email' => ['email'],
        'phone' => ['phone'],
        'address' => ['address'],
        'dob' => ['dob'],
    ]);

    expect($result['email'])->toBe('te***@***.com');
    expect($result['phone'])->toBe('******7890');
    expect($result['address'])->toBe('123 Main S***');
    expect($result['dob'])->toBe('1990');
    expect($result['name'])->toBe('John Doe');
});