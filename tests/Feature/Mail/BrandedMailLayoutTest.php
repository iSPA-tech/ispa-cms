<?php

use App\Listeners\BrandMailSubject;
use App\Support\Mail\MailBrand;
use Cms\Core\Services\CMSSettingRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Pretend the site has these settings (brand name, logo) without touching the database.
 *
 * @param array<string, mixed> $settings
 */
function fakeMailBrandSettings(array $settings): void
{
    app()->instance(CMSSettingRuntime::class, new class($settings) extends CMSSettingRuntime {
        /** @param array<string, mixed> $settings */
        public function __construct(private array $settings)
        {
        }

        public function get(string $path, mixed $default = null): mixed
        {
            return array_key_exists($path, $this->settings) ? $this->settings[$path] : $default;
        }
    });
}

beforeEach(function (): void {
    App::setLocale('vi');
    config(['app.url' => 'https://ispa.example', 'app.name' => 'Fallback App', 'ispa-cms.project_brand' => null]);
});

test('every subject gets the [Brand] - prefix exactly once', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => 'iSPA', 'setting.image.logo' => []]);

    expect(MailBrand::subject('Đặt lại mật khẩu'))->toBe('[iSPA] - Đặt lại mật khẩu')
        ->and(MailBrand::subject('[iSPA] - Đặt lại mật khẩu'))->toBe('[iSPA] - Đặt lại mật khẩu');
});

test('the brand falls back to the app name when the site has no brand setting', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => '', 'setting.image.logo' => []]);

    expect(MailBrand::name())->toBe('Fallback App');
});

test('the reset subject carries the date-time in the site time zone', function (): void {
    config(['ispa-cms.timezone' => 'Asia/Ho_Chi_Minh']);

    $at = Carbon::parse('2026-11-23 07:05:00', 'UTC');

    expect(MailBrand::stamped('Đặt lại mật khẩu', $at))->toBe('Đặt lại mật khẩu (23/11/2026 14:05)');
});

test('the MessageSending listener brands the outgoing subject', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => 'iSPA', 'setting.image.logo' => []]);
    $message = (new Email())->subject('Xác nhận địa chỉ email của bạn');

    (new BrandMailSubject())->handle(new MessageSending($message));

    expect($message->getSubject())->toBe('[iSPA] - Xác nhận địa chỉ email của bạn');
});

test('the layout prints the configured logo as an absolute URL with the brand as alt text', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => 'iSPA', 'setting.image.logo' => [['url' => 'storage/image/logo.png']]]);

    $html = view('mail.branded', ['title' => 'T', 'body' => '<p>Nội dung</p>'])->render();

    expect($html)->toContain('<img src="https://ispa.example/storage/image/logo.png" alt="iSPA"')
        ->toContain('Nội dung')
        ->toContain('Bạn nhận được email này từ iSPA.')
        ->toContain('© ' . now()->year . ' iSPA. Bảo lưu mọi quyền.');
});

test('the layout prints no image when the site has no logo, only the brand name', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => 'iSPA', 'setting.image.logo' => []]);

    $html = view('mail.branded', ['title' => 'T', 'body' => '<p>Nội dung</p>'])->render();

    expect($html)->not->toContain('<img')
        ->toContain('>iSPA</span>');
});

test('notification mail uses the frame and has no English leftovers in Vietnamese', function (): void {
    fakeMailBrandSettings(['setting.basic.brand' => 'iSPA', 'setting.image.logo' => []]);

    $html = (string) (new MailMessage())
        ->line('Bạn nhận được email này vì có yêu cầu đặt lại mật khẩu.')
        ->action('Đặt lại mật khẩu', 'https://ispa.example/reset/abc')
        ->render();

    expect($html)->toContain('Xin chào,')
        ->toContain('Trân trọng,')
        ->toContain('Nếu nút')
        ->toContain('Bảo lưu mọi quyền.')
        ->not->toContain("If you're having trouble")
        ->not->toContain('All rights reserved')
        ->not->toContain('Regards,');
});
