<?php

declare(strict_types=1);

use App\Services\Logger\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config([
        'services.telegram.token' => 'test-token',
        'services.telegram.chat_id' => '-100123',
        'logging.alert_throttle' => ['seconds' => 600, 'store' => 'array'],
    ]);
    TelegramService::init();
    Cache::store('array')->flush();
});

/**
 * Text of the n-th message sent to Telegram.
 */
function sentTelegramText(int $index): string
{
    return (string) Http::recorded()[$index][0]->data()['text'];
}

it('sends the same error once per window and reports the repeats with the next one', function (): void {
    Http::fake();
    $redisWriteFailed = static fn (): array => [
        'message' => 'CMSSettingRuntime redis write failed',
        'scope' => 'public',
        'exception' => new RuntimeException("OOM command not allowed when used memory > 'maxmemory'."),
    ];

    for ($i = 0; $i < 5; $i++) {
        TelegramService::error($redisWriteFailed());
    }
    Http::assertSentCount(1);

    // Another error is not held back by the first one.
    TelegramService::error(['message' => 'config:cache refresh after env update failed']);
    Http::assertSentCount(2);

    $this->travel(9)->minutes();
    TelegramService::error($redisWriteFailed());
    Http::assertSentCount(2);

    $this->travel(2)->minutes();
    TelegramService::error($redisWriteFailed());

    Http::assertSentCount(3);
    expect(sentTelegramText(2))->toContain('CMSSettingRuntime redis write failed')
        ->toContain('5 more times since the previous alert (the same alert is sent at most once per 10 min)');
});

it('throttles per level and never throttles info messages', function (): void {
    Http::fake();

    TelegramService::error(['message' => 'Honeypot ban applied']);
    TelegramService::warning(['message' => 'Honeypot ban applied']);
    TelegramService::info(['message' => 'Stripe event received']);
    TelegramService::info(['message' => 'Stripe event received']);

    Http::assertSentCount(4);
});

it('pauses on a Telegram 429 and logs it once instead of an error per rejected alert', function (): void {
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 30', 'parameters' => ['retry_after' => 30]], 429)
        ->push(['ok' => true])]);
    Log::spy();

    TelegramService::error(['message' => 'first alert']);
    TelegramService::error(['message' => 'second alert']);
    TelegramService::warning(['message' => 'third alert']);
    TelegramService::info(['message' => 'fourth alert']);

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->with('TelegramService: Telegram rate limit (429), alerts paused', ['retry_after' => 30]);
    Log::shouldNotHaveReceived('error');

    $this->travel(31)->seconds();
    TelegramService::error(['message' => 'after the pause']);

    Http::assertSentCount(2);
    expect(sentTelegramText(1))->toContain('after the pause')
        ->toContain('4 earlier alerts were not sent while Telegram rate limited this app');
});

it('still sends every alert when the throttle store is unusable', function (): void {
    Http::fake();
    config(['logging.alert_throttle.store' => 'missing-store']);

    TelegramService::error(['message' => 'CMSSettingRuntime redis write failed']);
    TelegramService::error(['message' => 'CMSSettingRuntime redis write failed']);

    Http::assertSentCount(2);
});
