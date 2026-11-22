<?php

use Cms\Core\Services\Security\LogRedactor;

beforeEach(function () {
    $this->redactor = new LogRedactor();
});

test('masks secrets under sensitive keys at any depth and keeps safe fields', function () {
    $result = $this->redactor->redact([
        'message' => 'Login failed',
        'status' => 401,
        'prompt_tokens' => 1200,
        'request' => [
            'password' => 'hunter2',
            'api_key' => 'sk_live_123',
            'headers' => [
                'Authorization' => 'Bearer abc.def',
                'Cookie' => 'laravel_session=xyz',
                'X-Api-Key' => 'k-1',
                'Accept' => 'application/json',
            ],
            'payment' => ['iban' => 'DE89370400440532013000', 'cvc' => 123, 'card_number' => 4242424242424242],
            'tokens' => ['access_token' => 'a', 'refresh_token' => 'b'],
            'remember' => true,
        ],
    ]);

    expect($result['message'])->toBe('Login failed')
        ->and($result['status'])->toBe(401)
        ->and($result['prompt_tokens'])->toBe(1200)
        ->and($result['request']['password'])->toBe(LogRedactor::MASK)
        ->and($result['request']['api_key'])->toBe(LogRedactor::MASK)
        ->and($result['request']['headers'])->toBe([
            'Authorization' => LogRedactor::MASK,
            'Cookie' => LogRedactor::MASK,
            'X-Api-Key' => LogRedactor::MASK,
            'Accept' => 'application/json',
        ])
        ->and($result['request']['payment'])->toBe(['iban' => LogRedactor::MASK, 'cvc' => LogRedactor::MASK, 'card_number' => LogRedactor::MASK])
        ->and($result['request']['tokens'])->toBe(['access_token' => LogRedactor::MASK, 'refresh_token' => LogRedactor::MASK])
        ->and($result['request']['remember'])->toBeTrue();
});

test('masks customer and billing personal fields but keeps product names', function () {
    $result = $this->redactor->redact([
        'product' => ['name' => 'Gold plan', 'price' => 1990],
        'customer_details' => [
            'name' => 'Jenny Rosen',
            'email' => 'jenny.rosen@example.com',
            'phone' => '+1 555 555 0123',
            'address' => ['line1' => '510 Townsend St', 'city' => 'San Francisco', 'postal_code' => '94103', 'country' => 'US'],
            'tax_exempt' => 'none',
        ],
        'billingDetails' => ['name' => 'Jenny Rosen'],
        'users' => [['name' => 'Ann', 'role' => 'admin']],
        'first_name' => 'Jenny',
        'shipping_address' => '1 Main St',
        'dob' => '1990-05-15',
    ]);

    expect($result['product'])->toBe(['name' => 'Gold plan', 'price' => 1990])
        ->and($result['customer_details']['name'])->toBe(LogRedactor::MASK)
        ->and($result['customer_details']['email'])->toBe('je***@***.com')
        ->and($result['customer_details']['phone'])->toBe('*******0123')
        ->and($result['customer_details']['address'])->toBe([
            'line1' => LogRedactor::MASK,
            'city' => LogRedactor::MASK,
            'postal_code' => LogRedactor::MASK,
            'country' => LogRedactor::MASK,
        ])
        ->and($result['customer_details']['tax_exempt'])->toBe('none')
        ->and($result['billingDetails']['name'])->toBe(LogRedactor::MASK)
        ->and($result['users'][0])->toBe(['name' => LogRedactor::MASK, 'role' => 'admin'])
        ->and($result['first_name'])->toBe(LogRedactor::MASK)
        ->and($result['shipping_address'])->toBe(LogRedactor::MASK)
        ->and($result['dob'])->toBe('1990');
});

test('masks emails and phone numbers inside free text', function () {
    $text = 'Order 1042 by john.doe@example.com, call 0912 345 678 or +84 912 345 679 before 2026-10-21 09:30';

    expect($this->redactor->redactString($text))
        ->toBe('Order 1042 by jo***@***.com, call ******5678 or *******5679 before 2026-10-21 09:30');
});

test('masks only Luhn-valid card-like digit runs', function () {
    $text = 'card 4242 4242 4242 4242, other 4242-4242-4242-4241, ts 1792596879111, id pi_3N1234567890123456';

    expect($this->redactor->redactString($text))
        ->toBe('card ************4242, other 4242-4242-4242-4241, ts 1792596879111, id pi_3N1234567890123456');
});

test('keeps ids, timestamps, versions and route actions readable', function () {
    $data = [
        'id' => 'evt_1NabcXYZ',
        'created' => 1792596879,
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'action' => 'App\\Http\\Controllers\\OrderController@store',
        'version' => '8.0.12',
        'amount' => 1596.5,
        'phone_verified_at' => '2026-10-21 10:00:00',
        'email_template' => 'welcome',
        'type' => null,
    ];

    expect($this->redactor->redact($data))->toBe($data);
});

test('converts arrayable and json-serializable objects and leaves throwables untouched', function () {
    $exception = new RuntimeException('boom');
    $stripeLike = new class implements JsonSerializable {
        public function jsonSerialize(): array
        {
            return ['object' => 'customer', 'email' => 'a.b@example.org'];
        }
    };

    $result = $this->redactor->redact(['exception' => $exception, 'customer' => $stripeLike]);

    expect($result['exception'])->toBe($exception)
        ->and($result['customer'])->toBe(['object' => 'customer', 'email' => 'a.***@***.org']);
});

test('redacts sensitive query parameters in URLs', function () {
    $url = $this->redactor->redactUrl('https://cms.test/api/orders?page=2&token=abc123&email=jane%40example.com');

    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://cms.test/api/orders?')
        ->and($query)->toBe(['page' => '2', 'token' => LogRedactor::MASK, 'email' => 'ja***@***.com'])
        ->and($this->redactor->redactUrl('https://cms.test/users/jane@example.com'))->toBe('https://cms.test/users/ja***@***.com');
});
