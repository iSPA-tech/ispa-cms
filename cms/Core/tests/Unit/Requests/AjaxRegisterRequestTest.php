<?php

use Cms\Core\Http\Requests\Auth\AjaxRegisterRequest;
use Cms\Core\Http\Requests\Auth\RegisterRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
        });
    }
});

function ajaxRegisterPayload(string $username, string $email): array
{
    return [
        'email' => $email,
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'username' => $username,
        'first_name' => 'Nguyen',
        'last_name' => 'Van A',
    ];
}

test('ajax registration accepts institution-style username and valid email domains', function () {
    $rules = (new AjaxRegisterRequest())->rules();

    foreach ([
        ajaxRegisterPayload('DHV20251021', 'tolgacan@em1.thematinggrounds.com'),
        ajaxRegisterPayload('DHV20251022', 'ijmnmqgd@immenseignite.info'),
    ] as $payload) {
        expect(Validator::make($payload, $rules)->passes())->toBeTrue();
    }
});

test('ajax registration rejects numeric, whitespace, and Vietnamese usernames', function (string $username) {
    $validator = Validator::make(
        ajaxRegisterPayload($username, 'member@example.test'),
        (new AjaxRegisterRequest())->rules(),
    );

    expect($validator->fails())->toBeTrue();
})->with([
    '12345',
    'DHV 20251021',
    'ĐHV20251021',
]);

test('legacy web registration request keeps its existing username contract', function () {
    expect((new RegisterRequest())->rules()['username'])
        ->toBe('required|unique:users,username');
});
