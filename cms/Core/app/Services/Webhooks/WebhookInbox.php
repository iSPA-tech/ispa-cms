<?php

declare(strict_types=1);

namespace Cms\Core\Services\Webhooks;

use Cms\Core\Contracts\WebhookHandler;
use Cms\Core\Jobs\ProcessWebhookReceiptJob;
use Cms\Core\Models\WebhookReceipt;
use Cms\Core\Services\Security\LogRedactor;
use RuntimeException;
use Throwable;

/**
 * Shared inbox for incoming webhooks: store once, process, retry, replay.
 *
 * Config `core.webhooks.mode` selects inline processing (default) or a queued job.
 * Payload content is never logged; `last_error` holds the exception class and a redacted short message.
 */
class WebhookInbox
{
    private const MAX_ERROR_LENGTH = 1000;

    /** Minutes after which an unfinished (received/processing) receipt may be claimed again. */
    private const STALE_MINUTES = 10;

    /** @var array<string, class-string<WebhookHandler>> */
    private array $handlers = [];

    /**
     * Register the handler class for a provider (module service providers call this).
     *
     * @param string                       $provider     Provider key, e.g. "salehunting.telegram".
     * @param class-string<WebhookHandler> $handlerClass Handler implementation.
     */
    public function registerHandler(string $provider, string $handlerClass): void
    {
        $this->handlers[$provider] = $handlerClass;
    }

    /**
     * Store an event once, or claim an existing one that needs another attempt.
     *
     * Returns the receipt for a new event, for a `failed` one, and for a received/processing one
     * untouched for 10+ minutes (never finished). The claim is one conditional UPDATE, so of two
     * concurrent deliveries only one gets the receipt. Returns null for a true duplicate
     * (processed, or still fresh).
     *
     * @param string               $provider Provider key.
     * @param string               $eventId  Provider event id (max 191 chars).
     * @param array<string, mixed> $payload  Decoded webhook body.
     */
    public function receive(string $provider, string $eventId, array $payload): ?WebhookReceipt
    {
        $now = now();
        $inserted = WebhookReceipt::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'status' => WebhookReceipt::STATUS_RECEIVED,
            'attempts' => 0,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $query = WebhookReceipt::query()->where('provider', $provider)->where('event_id', $eventId);

        if ($inserted === 0) {
            $claimed = (clone $query)
                ->where(static function ($q) use ($now): void {
                    $q->where('status', WebhookReceipt::STATUS_FAILED)
                        ->orWhere(static function ($q) use ($now): void {
                            $q->whereIn('status', [WebhookReceipt::STATUS_RECEIVED, WebhookReceipt::STATUS_PROCESSING])
                                ->where('updated_at', '<', $now->copy()->subMinutes(self::STALE_MINUTES));
                        });
                })
                ->update(['status' => WebhookReceipt::STATUS_RECEIVED, 'updated_at' => $now]);

            if ($claimed === 0) {
                return null;
            }
        }

        return $query->first();
    }

    /**
     * Process a receipt now, or queue it, depending on `core.webhooks.mode`.
     *
     * In inline mode a handler exception is rethrown after the receipt is marked failed.
     */
    public function dispatch(WebhookReceipt $receipt): void
    {
        if (config('core.webhooks.mode', 'inline') === 'queue') {
            ProcessWebhookReceiptJob::dispatch((int) $receipt->id);

            return;
        }

        $this->process((int) $receipt->id);
    }

    /**
     * Run the provider handler for a receipt; marks it processed, or failed (and rethrows).
     */
    public function process(int $receiptId): void
    {
        $receipt = WebhookReceipt::query()->find($receiptId);

        if ($receipt === null) {
            return;
        }

        $receipt->forceFill([
            'status' => WebhookReceipt::STATUS_PROCESSING,
            'attempts' => min(65535, (int) $receipt->attempts + 1),
        ])->save();

        try {
            $this->resolveHandler((string) $receipt->provider)->handle((array) $receipt->payload);
        } catch (Throwable $e) {
            $receipt->forceFill([
                'status' => WebhookReceipt::STATUS_FAILED,
                'last_error' => $this->describe($e),
            ])->save();

            throw $e;
        }

        $receipt->forceFill([
            'status' => WebhookReceipt::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /**
     * Resolve the handler registered for a provider.
     *
     * @throws RuntimeException When no handler is registered.
     */
    private function resolveHandler(string $provider): WebhookHandler
    {
        $class = $this->handlers[$provider] ?? (config('core.webhooks.handlers', [])[$provider] ?? null);

        if (! is_string($class) || ! is_a($class, WebhookHandler::class, true)) {
            throw new RuntimeException("No webhook handler registered for provider [{$provider}]");
        }

        return app($class);
    }

    /**
     * Build the stored error text: exception class plus redacted, shortened message.
     */
    private function describe(Throwable $e): string
    {
        $text = $e::class . ': ' . app(LogRedactor::class)->redactString($e->getMessage());

        return mb_substr($text, 0, self::MAX_ERROR_LENGTH);
    }
}
