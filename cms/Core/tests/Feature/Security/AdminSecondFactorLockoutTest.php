<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * the limit of 8 wrong second-factor codes at admin sign-in must survive the failed request. The counter
 * used to roll back with the transaction of the failing verification, so the code could be guessed without bound.
 * Also: the e-mail log written for a verification code holds no plain code.
 */

use App\Listeners\SendCodeVerifyHandle;
use App\Models\User;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\AuthChallenge;
use Cms\Core\Models\EmailLog;
use Cms\Core\Services\Security\AdminMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Make an admin account and a pending e-mail-code challenge with a known code. */
function t07cChallenge(string $code = '123456'): AuthChallenge
{
    Event::fake([SendCodeVerify::class]);
    User::query()->forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Filler', 'username' => 'filler-t07c',
        'email' => 'filler-t07c@example.com', 'password' => bcrypt('x'), 'type' => 0, 'level' => 0]);
    $user = User::query()->forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'username' => 'adm-t07c',
        'email' => 'adm-t07c@example.com', 'email_verified_at' => now(), 'password' => bcrypt('secret123'),
        'type' => 0, 'level' => 0]);
    $service = app(AdminMfaService::class);
    $challenge = $service->createChallenge($user, '127.0.0.1', 'pest', false, [], [AdminMfaService::METHOD_EMAIL_CODE]);
    $challenge->email_code_hash = hash_hmac('sha256', $challenge->uuid . '|' . $code, (string) config('app.key'));
    $challenge->save();

    return $challenge;
}

/** One wrong guess; returns the exception message. */
function t07cGuess(AuthChallenge $challenge, string $code): string
{
    try {
        app(AdminMfaService::class)->verifyChallenge(AuthChallenge::query()->findOrFail($challenge->id), $code);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return 'ok';
}

test('eight wrong codes lock the challenge and the ninth guess is refused even with the right code', function () {
    $challenge = t07cChallenge();
    $service = app(AdminMfaService::class);
    for ($i = 1; $i <= 8; $i++) {
        expect(t07cGuess($challenge, '00000' . $i))->not->toBe('ok');
        expect((int) AuthChallenge::query()->find($challenge->id)->attempt_count)->toBe($i);
    }

    expect(AuthChallenge::query()->find($challenge->id)->status)->toBe('failed');
    expect(fn () => $service->verifyChallenge(AuthChallenge::query()->find($challenge->id), '123456'))
        ->toThrow(RuntimeException::class);
});

test('a correct code before the limit still signs in', function () {
    $challenge = t07cChallenge();
    $service = app(AdminMfaService::class);
    for ($i = 1; $i <= 7; $i++) {
        t07cGuess($challenge, '00000' . $i);
    }

    $result = $service->verifyChallenge(AuthChallenge::query()->find($challenge->id), '123456');

    expect($result['type'])->toBe('auth');
});

test('the e-mail log of a verification code holds no plain code', function () {
    Mail::fake();
    $challenge = t07cChallenge();
    $user = $challenge->user;

    app(SendCodeVerifyHandle::class)->handle(new SendCodeVerify($user, '987654', 'two_fa'));

    $log = EmailLog::query()->where('type', 'two_fa')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect(json_encode($log->getAttributes()))->not->toContain('987654');
});
