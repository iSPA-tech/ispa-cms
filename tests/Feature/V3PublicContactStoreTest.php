<?php

use App\Events\PusherContactCreate;
use App\Events\SendConfirmToContactor;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\Contact;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Cms\Core\Services\Captcha\CaptchaVerifyResult;
use Cms\Core\Models\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\WithApiVerifyHeader;

uses(TestCase::class, RefreshDatabase::class, WithApiVerifyHeader::class);

beforeEach(function () {
    Event::fake();

    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
    ]);
});

test('v3 public contact stores contact and inbox email using canonical fields', function () {
    $captcha = \Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('valid-turnstile-token', \Mockery::type('string'))
        ->andReturn(new CaptchaVerifyResult(true, 'cloudflare'));
    app()->instance(CaptchaServiceInterface::class, $captcha);

    $payload = [
        'name' => 'Nguyen Van A',
        'email' => 'contact@example.com',
        'phone_number' => '0909123456',
        'company' => 'iSPA',
        'country' => 'VN',
        'message' => 'Xin chao Acme',
        'source' => 'acme-web-contact',
        'page_url' => 'https://acme.test/contact',
        'subject' => 'New Contact from Acme Website',
        'cf-turnstile-response' => 'valid-turnstile-token',
    ];

    $this->withHeaders($this->withApiVerify())
        ->postJson('/api/v3/contact', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', __('mgs.form.success'));

    $contact = Contact::query()->first();
    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Nguyen Van A');
    expect($contact->email)->toBe('contact@example.com');
    expect($contact->mobile)->toBe('0909123456');
    expect($contact->organization)->toBe('iSPA');
    expect($contact->source)->toBe('acme-web-contact');
    expect($contact->url)->toBe('https://acme.test/contact');

    $email = Email::query()->first();
    expect($email)->not->toBeNull();
    expect($email->contact_id)->toBe($contact->id);
    expect($email->from_email)->toBe('contact@example.com');
    expect($email->content)->toBe('Xin chao Acme');

    Event::assertDispatched(PusherContactCreate::class);
    Event::assertDispatched(
        SendConfirmToContactor::class,
        fn(SendConfirmToContactor $event) => $event->data === 'contact@example.com'
            && $event->targetType === Contact::class
            && $event->targetId === $contact->id
    );
});

test('v3 public contact accepts legacy kebab-case field aliases', function () {
    $captcha = \Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('legacy-turnstile-token', \Mockery::type('string'))
        ->andReturn(new CaptchaVerifyResult(true, 'cloudflare'));
    app()->instance(CaptchaServiceInterface::class, $captcha);

    $payload = [
        'full-name' => 'Legacy Caller',
        'email' => 'legacy@example.com',
        'phone-number' => '0988111222',
        'company' => 'Legacy Inc',
        'message' => 'Legacy payload',
        'cf-turnstile-response' => 'legacy-turnstile-token',
    ];

    $this->withHeaders($this->withApiVerify())
        ->postJson('/api/v3/contact', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $contact = Contact::query()->first();
    expect($contact?->name)->toBe('Legacy Caller');
    expect($contact?->mobile)->toBe('0988111222');
    expect($contact?->organization)->toBe('Legacy Inc');
});

test('v3 public contact rejects submissions when captcha verification fails', function () {
    $captcha = \Mockery::mock(CaptchaServiceInterface::class);
    $captcha->shouldReceive('verify')
        ->once()
        ->with('', \Mockery::type('string'))
        ->andReturn(new CaptchaVerifyResult(false, 'cloudflare', 'missing_token'));
    app()->instance(CaptchaServiceInterface::class, $captcha);

    $payload = [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'message' => 'This should be rejected',
    ];

    $this->withHeaders($this->withApiVerify())
        ->postJson('/api/v3/contact', $payload)
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Captcha verification failed. Please try again.')
        ->assertJsonPath('data.provider', 'cloudflare')
        ->assertJsonPath('data.error', 'missing_token');

    expect(Contact::query()->count())->toBe(0);
    expect(Email::query()->count())->toBe(0);
});
