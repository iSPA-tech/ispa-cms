<?php

use App\Listeners\SendCodeVerifyHandle;
use App\Mail\SendMail;
use App\Models\User;
use App\Http\Controllers\MailController;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['ispa-cms.available_languages' => ['en', 'vi']]);
});

function makeLocalizedMfaMailUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Localized Admin',
        'username' => 'localized-admin-' . Str::lower(Str::random(6)),
        'email' => 'localized-admin-' . Str::lower(Str::random(6)) . '@example.test',
        'email_verified_at' => now(),
        'password' => bcrypt('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

test('admin MFA event preserves the request locale across the queued listener boundary', function (): void {
    App::setLocale('en');

    $event = unserialize(serialize(new SendCodeVerify(
        makeLocalizedMfaMailUser(),
        '123456',
        'two_fa'
    )));

    expect($event->locale)->toBe('en');
});

test('queued admin MFA mail uses the locale captured when the event was dispatched', function (): void {
    Mail::fake();
    App::setLocale('en');
    $event = unserialize(serialize(new SendCodeVerify(
        makeLocalizedMfaMailUser(),
        '123456',
        'two_fa'
    )));

    App::setLocale('vi');
    app(SendCodeVerifyHandle::class)->handle($event);

    Mail::assertSent(SendMail::class, function (SendMail $mail): bool {
        $html = $mail->render();

        return str_ends_with($mail->subject, 'Two-factor authentication code')
            && str_contains($html, 'Hello Localized Admin, use the verification code below')
            && str_contains($html, 'Verification code')
            && str_contains($html, 'do not share this code')
            && str_contains($html, 'You received this email from')
            && !str_contains($html, 'mgs.code.two_fa');
    });

    expect(App::currentLocale())->toBe('vi');

    $log = EmailLog::query()->firstOrFail();
    expect($log->content['locale'] ?? null)->toBe('en');
    $preview = app(MailController::class)->show($log->uuid)->render();
    expect(App::currentLocale())->toBe('en');

    expect($preview)
        ->toContain('use the verification code below')
        ->not->toContain('hãy sử dụng mã xác thực bên dưới');
});

test('queued admin MFA mail falls back safely when a pre-deploy event has no locale', function (): void {
    Mail::fake();
    App::setLocale('vi');
    $event = new SendCodeVerify(makeLocalizedMfaMailUser(), '123456', 'two_fa');
    unset($event->locale);
    $event = unserialize(serialize($event));

    app(SendCodeVerifyHandle::class)->handle($event);

    Mail::assertSent(SendMail::class, fn (SendMail $mail): bool =>
        str_ends_with($mail->subject, 'Mã xác thực hai bước')
    );
});

test('admin MFA mail renders Vietnamese copy and a formatted footer timestamp', function (): void {
    config(['ispa-cms.timezone' => 'Asia/Ho_Chi_Minh']);
    Carbon::setTestNow(Carbon::parse('2026-08-17 12:08:09', 'Asia/Ho_Chi_Minh'));
    App::setLocale('vi');

    $html = view('mail.two_fa', [
        'subject' => __('mgs.code.two_fa'),
        'subTitle' => __('mgs.code.two_fa'),
        'brand' => 'Học Viện Thần Học Đa Minh',
        'code' => '123456',
        'user' => (object) ['name' => '   '],
        'uuid' => 'mail-preview',
    ])->render();

    expect($html)
        ->toContain('Mã xác thực hai bước')
        ->toContain('Xin chào Quản trị viên')
        ->toContain('Mã này hết hạn sau 5 phút')
        ->toContain('Nếu bạn không thực hiện lần đăng nhập này')
        ->toContain('Không xem được email này?')
        ->toContain('Xem trực tuyến')
        ->toContain('Bạn nhận được email này từ Học Viện Thần Học Đa Minh.')
        ->toContain('Email được gửi lúc 2026-08-17 12:08:09.')
        ->not->toContain('Cannot view mail?')
        ->not->toContain(' api.')
        ->not->toContain('mgs.code.two_fa');
});

test('shared mail layout keeps narrow clients responsive', function (): void {
    App::setLocale('en');

    $html = view('mail.two_fa', [
        'subject' => __('mgs.code.two_fa'),
        'subTitle' => __('mgs.code.two_fa'),
        'brand' => 'Example Academy',
        'code' => '123456',
        'user' => (object) ['name' => 'Admin User'],
        'uuid' => 'mail-preview',
    ])->render();

    expect(substr_count($html, 'name="viewport"'))->toBe(1)
        ->and($html)
        ->not->toContain('min-width: 512px')
        ->not->toContain('width="512"')
        ->toContain('max-width:600px')
        ->not->toContain('class="padding: 0; margin: 0"')
        ->not->toContain('color: #22222;');
});

afterEach(function (): void {
    Carbon::setTestNow();
    App::setLocale(config('app.locale'));
});
