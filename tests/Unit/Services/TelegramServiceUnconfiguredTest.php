<?php

use App\Services\Logger\TelegramService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

function resetTelegramStatics(): void
{
    $ref = new ReflectionClass(TelegramService::class);
    foreach (['token', 'chatId', 'apiUrl'] as $prop) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue(null, '');
    }
}

beforeEach(function () {
    resetTelegramStatics();
    config(['logging.alert_throttle.seconds' => 0, 'logging.alert_throttle.store' => 'array']);
});

afterEach(fn () => resetTelegramStatics());

it('sends nothing and logs no error when the token is empty', function () {
    config(['services.telegram.token' => '', 'services.telegram.chat_id' => '123']);
    Http::fake();
    Log::spy();

    expect(TelegramService::send(['message' => 'hello']))->toBeFalse();

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('error');
});

it('sends nothing and logs no error when the chat id is empty', function () {
    config(['services.telegram.token' => 'abc', 'services.telegram.chat_id' => '']);
    Http::fake();
    Log::spy();

    TelegramService::error(['message' => 'boom']);
    TelegramService::info(['message' => 'hi']);

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('error');
});

it('sends the message when token and chat id are set', function () {
    config(['services.telegram.token' => 'abc', 'services.telegram.chat_id' => '123']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    expect(TelegramService::send(['message' => 'hello']))->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/botabc/sendMessage')
        && $r['chat_id'] === '123');
});
