<?php

uses(\Tests\TestCase::class);

use App\View\Components\Captcha;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Cms\Core\Services\CMSSettingUnified;
test('captcha uses configured site key when setting metadata is unavailable', function (): void {
    $settings = \Mockery::mock(CMSSettingUnified::class);
    $settings->shouldReceive('get')->once()->andThrow(new \RuntimeException('settings database unavailable'));
    app()->instance(CMSSettingUnified::class, $settings);
    config(['services.turnstile.site_key' => 'configured-turnstile-key']);
    $service = \Mockery::mock(CaptchaServiceInterface::class);
    $service->shouldReceive('provider')->once()->andReturn('cloudflare');

    $component = new Captcha($service, 'contact');

    expect($component->siteKey)->toBe('configured-turnstile-key')
        ->and($component->active)->toBeTrue()
        ->and($component->invalid)->toBeFalse();
});

test('captcha resolves hcaptcha site key from shared configuration fallback', function (): void {
    $settings = \Mockery::mock(CMSSettingUnified::class);
    $settings->shouldReceive('get')->once()->andThrow(new \RuntimeException('settings database unavailable'));
    app()->instance(CMSSettingUnified::class, $settings);
    config(['services.hcaptcha.site_key' => 'configured-hcaptcha-key']);
    $service = \Mockery::mock(CaptchaServiceInterface::class);
    $service->shouldReceive('provider')->once()->andReturn('hcaptcha');

    $component = new Captcha($service, 'login');

    expect($component->siteKey)->toBe('configured-hcaptcha-key')
        ->and($component->active)->toBeTrue()
        ->and($component->invalid)->toBeFalse();
});

test('captcha rejects encrypted runtime artifacts and uses a valid configured site key', function (): void {
    $settings = \Mockery::mock(CMSSettingUnified::class);
    $settings->shouldReceive('get')->once()->andReturn('eyJpdiI6ImZvcmVpZ24ta2V5LWNpcGhlcnRleHQ9PSI=');
    app()->instance(CMSSettingUnified::class, $settings);
    config(['services.turnstile.site_key' => 'configured-turnstile-key']);
    $service = \Mockery::mock(CaptchaServiceInterface::class);
    $service->shouldReceive('provider')->once()->andReturn('cloudflare');

    $component = new Captcha($service, 'newsletter');

    expect($component->siteKey)->toBe('configured-turnstile-key')
        ->and($component->active)->toBeTrue()
        ->and($component->invalid)->toBeFalse();
});

test('captcha fails closed when every site key candidate is invalid', function (): void {
    $settings = \Mockery::mock(CMSSettingUnified::class);
    $settings->shouldReceive('get')->once()->andReturn('eyJpdiI6ImZvcmVpZ24ta2V5LWNpcGhlcnRleHQ9PSI=');
    app()->instance(CMSSettingUnified::class, $settings);
    config(['services.turnstile.site_key' => '']);
    $service = \Mockery::mock(CaptchaServiceInterface::class);
    $service->shouldReceive('provider')->once()->andReturn('cloudflare');

    $component = new Captcha($service, 'newsletter');

    expect($component->siteKey)->toBe('')
        ->and($component->active)->toBeFalse()
        ->and($component->invalid)->toBeTrue();
});

test('captcha view exposes the shared hcaptcha turnstile compatibility bridge', function (): void {
    $source = (string) file_get_contents(resource_path('views/components/captcha.blade.php'));

    expect($source)
        ->toContain('$provider === \'hcaptcha\'')
        ->toContain('https://js.hcaptcha.com/1/api.js')
        ->toContain('verificationProvider')
        ->toContain('window.hcaptcha')
        ->toContain('window.turnstile = {')
        ->toContain('return api.render(element ?? container, {')
        ->toContain("'chalexpired-callback': options['timeout-callback']")
        ->toContain('return api.getResponse(widgetId)')
        ->not->toContain('return api.execute(widgetId)');
});
