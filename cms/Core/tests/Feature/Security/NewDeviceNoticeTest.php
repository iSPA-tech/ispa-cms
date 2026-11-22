<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * optional new-device notice. Off by default (nothing is sent); when on, the first token of an account sends
 * nothing and a later token from a network or device label not seen in its recent tokens sends exactly one queued
 * notice that names the device label and the time, never an address or a token.
 */

use App\Models\User;
use Cms\Core\Notifications\NewDeviceSignInNotification;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\NewDeviceDetector;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const P10_NDN_CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Create a plain account. */
function p10NdnUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Notice Owner',
        'username' => 'ndn-' . Str::lower(Str::random(6)),
        'email' => 'ndn-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

/** Request from an address with a user agent. */
function p10NdnRequest(string $ip, string $ua = P10_NDN_CHROME_MAC): Request
{
    return Request::create('/api/account/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
}

/** Sign in through the issuer. */
function p10NdnSignIn(User $user, string $ip, string $name = 'Laptop', string $ua = P10_NDN_CHROME_MAC): void
{
    app(ApiTokenIssuer::class)->issue($user, TokenAudience::ACCOUNT, $name, ['*'], p10NdnRequest($ip, $ua), null, 'account.login');
}

afterEach(function () {
    Carbon::setTestNow();
});

test('the setting is off by default and nothing is sent', function () {
    Notification::fake();
    $user = p10NdnUser();

    expect((new TokenSettings())->notifyNewDevice())->toBeFalse();

    p10NdnSignIn($user, '203.0.113.10');
    p10NdnSignIn($user, '198.51.100.20', 'Phone');

    Notification::assertNothingSent();
});

test('on: the first sign-in sends nothing and a new network sends exactly one notice', function () {
    Notification::fake();
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();

    p10NdnSignIn($user, '203.0.113.10');
    Notification::assertNothingSent();

    p10NdnSignIn($user, '198.51.100.20', 'Laptop 2');
    Notification::assertSentToTimes($user, NewDeviceSignInNotification::class, 1);
});

test('on: the same network and device again sends nothing', function () {
    Notification::fake();
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();

    p10NdnSignIn($user, '203.0.113.10');
    p10NdnSignIn($user, '203.0.113.99');

    Notification::assertNothingSent();
});

test('on: a new device label on a known network sends one notice', function () {
    Notification::fake();
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();

    p10NdnSignIn($user, '203.0.113.10');
    p10NdnSignIn($user, '203.0.113.11', 'Win', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0');

    Notification::assertSentToTimes($user, NewDeviceSignInNotification::class, 1);
});

test('the notice names the device label and the time, never the address or a token, and is queued', function () {
    Notification::fake();
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();
    Carbon::setTestNow('2026-11-11T10:00:00+00:00');

    p10NdnSignIn($user, '203.0.113.10');
    p10NdnSignIn($user, '198.51.100.20', 'Laptop 2');

    Notification::assertSentTo($user, NewDeviceSignInNotification::class, function (NewDeviceSignInNotification $notice) use ($user) {
        $stored = json_encode($notice->toArray($user));
        $mail = json_encode($notice->toMail($user)->toArray());

        return $notice instanceof ShouldQueue
            && ($notice->payload['device'] ?? null) === 'Chrome on OS X'
            && ($notice->payload['at'] ?? null) === '2026-11-11T10:00:00+00:00'
            && ! str_contains($stored . $mail, '198.51.100')
            && ! str_contains($stored . $mail, '203.0.113')
            && ! str_contains($stored . $mail, '|');
    });
});

test('the mail reads from translation keys in vi and en and shows the time in the site timezone', function () {
    config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
    $notice = new NewDeviceSignInNotification('Chrome on OS X', '2026-11-11T10:00:00+00:00');
    $user = p10NdnUser();

    foreach (['vi' => ['Thiết bị: Chrome on OS X', '17:00'], 'en' => ['Device: Chrome on OS X', '5:00 PM']] as $locale => [$device, $clock]) {
        app()->setLocale($locale);
        $translator = app('translator');
        $translator->addLines([
            'mgs.new_device_notice_device' => $locale === 'vi' ? 'Thiết bị: :device' : 'Device: :device',
            'mgs.new_device_notice_time' => $locale === 'vi' ? 'Thời gian: :time' : 'Time: :time',
        ], $locale);
        $lines = implode("\n", $notice->toMail($user)->introLines);
        expect($lines)->toContain($device)->and($lines)->toContain($clock)->and($lines)->toContain('UTC+07:00');
    }
    app()->setLocale(config('app.locale'));
});

test('the sidecar carries vi and en rows for every notice key', function () {
    $rows = collect(require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php'))->keyBy('name');

    foreach (['subject', 'intro', 'device', 'time', 'outro'] as $part) {
        $row = $rows->get('mgs.new_device_notice_' . $part);
        expect($row)->not->toBeNull()->and($row['text']['vi'])->not->toBe('')->and($row['text']['en'])->not->toBe('')->and($row['for_web'])->toBe(1);
    }
});

test('without a request there is no device record and no notice', function () {
    Notification::fake();
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();

    p10NdnSignIn($user, '203.0.113.10');
    app(ApiTokenIssuer::class)->issue($user, TokenAudience::ACCOUNT, 'Job', ['*'], null, null, 'job');

    Notification::assertNothingSent();
});

test('the detector only compares recent tokens', function () {
    $user = p10NdnUser();
    Carbon::setTestNow('2026-01-01 00:00:00');
    p10NdnSignIn($user, '203.0.113.10');
    Carbon::setTestNow('2026-11-11 00:00:00');

    $detector = app(NewDeviceDetector::class);

    expect($detector->isNewDevice($user, '203.0.113.0/24', 'Chrome on OS X'))->toBeFalse();
});

test('a failing notification never breaks the sign-in', function () {
    (new TokenSettings())->setNotifyNewDevice(true);
    $user = p10NdnUser();
    p10NdnSignIn($user, '203.0.113.10');

    Notification::shouldReceive('send')->andThrow(new RuntimeException('mail down'));
    $new = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ACCOUNT, 'Laptop 2', ['*'], p10NdnRequest('198.51.100.20'), null, 'account.login');

    expect($new->plainTextToken)->toContain('|');
});
