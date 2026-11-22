<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Facades\CMSLogger;
use Cms\Core\Providers\CMSLoggerServiceProvider;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Rebind the CMSLogger singleton for the given provider, as the service provider does at boot.
 */
function bindCmsLoggerProvider(string $provider): void
{
    config([
        'logging.logger_provider' => $provider,
        'services.telegram.token' => 'test-token',
        'services.telegram.chat_id' => '-100123',
        'services.discord.bot_token' => 'test-bot',
        'services.discord.default_channel_id' => '42',
        'services.discord.icon_url' => 'https://cms.test/icon.png',
    ]);

    (new CMSLoggerServiceProvider(app()))->register();
    CMSLogger::clearResolvedInstance('CMSLogger');
}

/**
 * A verified Stripe checkout.session.completed event as returned by $event->toArray().
 *
 * @return array<string, mixed>
 */
function stripeCheckoutEventArray(): array
{
    return [
        'id' => 'evt_1NabcXYZ',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'created' => 1792596879,
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_a1b2c3',
                'object' => 'checkout.session',
                'amount_total' => 199000,
                'currency' => 'vnd',
                'customer_email' => 'jenny.rosen@example.com',
                'customer_details' => [
                    'name' => 'Jenny Rosen',
                    'email' => 'jenny.rosen@example.com',
                    'phone' => '+15555550123',
                    'address' => ['line1' => '510 Townsend St', 'city' => 'San Francisco', 'postal_code' => '94103', 'country' => 'US'],
                ],
                'metadata' => ['order_id' => '1042'],
                'payment_status' => 'paid',
            ],
        ],
    ];
}

dataset('cms logger sinks', [
    'telegram' => ['telegram'],
    'discord' => ['discord'],
]);

test('a Stripe-like event reaches the sink redacted', function (string $provider) {
    Http::fake();
    bindCmsLoggerProvider($provider);

    // Same call shape as StripeWebhookController after signature verification
    CMSLogger::info(['message' => 'Stripe event received', ...stripeCheckoutEventArray()]);

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) {
        $body = $request->body();

        foreach (['jenny.rosen', 'Jenny Rosen', '5555550123', '510 Townsend', 'San Francisco', '94103'] as $leak) {
            expect($body)->not->toContain($leak);
        }

        expect($body)->toContain('Stripe event received')
            ->and($body)->toContain('checkout.session.completed')
            ->and($body)->toContain('cs_test_a1b2c3')
            ->and($body)->toContain('order_id')
            ->and($body)->toContain('je***@***.com')
            ->and($body)->toContain('[REDACTED]');

        return true;
    });
})->with('cms logger sinks');

test('free-text messages are masked before they are sent', function (string $provider) {
    Http::fake();
    bindCmsLoggerProvider($provider);

    CMSLogger::error(['message' => 'Duplicate entry jane.doe@example.com for phone 0912345678', 'token' => 'secret-value']);

    Http::assertSent(function (HttpRequest $request) {
        $body = $request->body();

        expect($body)->not->toContain('jane.doe@example.com')
            ->and($body)->not->toContain('0912345678')
            ->and($body)->not->toContain('secret-value')
            ->and($body)->toContain('ja***@***.com')
            ->and($body)->toContain('******5678');

        return true;
    });
})->with('cms logger sinks');
