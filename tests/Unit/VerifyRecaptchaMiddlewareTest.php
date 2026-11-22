<?php

use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\VerifyRecaptchaMiddleware;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Cms\Core\Services\Captcha\CaptchaVerifyResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

test('recaptcha middleware accepts the standard hcaptcha response field', function (): void {
    $captcha = Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('hcaptcha-token', Mockery::type('string'))
        ->andReturn(new CaptchaVerifyResult(ok: true, provider: 'hcaptcha'));

    $middleware = new VerifyRecaptchaMiddleware($captcha);
    $request = Request::create('/login', 'POST', [
        'h-captcha-response' => 'hcaptcha-token',
    ]);

    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

test('recaptcha middleware without a parameter verifies without an action context', function (): void {
    $captcha = Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->withArgs(fn (...$args): bool => count($args) === 2 && $args[0] === 'cf-token')
        ->andReturn(new CaptchaVerifyResult(ok: true, provider: 'cloudflare'));

    $middleware = new VerifyRecaptchaMiddleware($captcha);
    $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'cf-token']);

    expect($middleware->handle($request, fn () => response('ok'))->getContent())->toBe('ok');
});

test('recaptcha middleware passes its parameter as the expected action', function (): void {
    $captcha = Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('cf-token', Mockery::type('string'), ['action' => 'login'])
        ->andReturn(new CaptchaVerifyResult(ok: true, provider: 'cloudflare'));

    $middleware = new VerifyRecaptchaMiddleware($captcha);
    $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'cf-token']);

    expect($middleware->handle($request, fn () => response('ok'), 'login')->getContent())->toBe('ok');
});

test('the recaptcha:login route middleware alias hands the action to the captcha service and blocks a failed check', function (): void {
    $captcha = Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('cf-token', Mockery::any(), ['action' => 'login'])
        ->andReturn(new CaptchaVerifyResult(ok: false, provider: 'cloudflare', error: 'action_mismatch'));
    app()->instance(CaptchaServiceInterface::class, $captcha);

    Route::post('/_recaptcha-action-probe', fn () => response('reached'))->middleware('recaptcha:login');

    // The global HTTPS redirect would answer a plain test request before the route runs.
    $this->withoutMiddleware(HttpsProtocol::class);

    $this->postJson('/_recaptcha-action-probe', ['cf-turnstile-response' => 'cf-token'])
        ->assertStatus(400)
        ->assertJsonPath('data.error', 'action_mismatch')
        ->assertJsonPath('data.provider', 'cloudflare')
        ->assertDontSee('reached');
});
