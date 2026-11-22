<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Models\WebhookReceipt;
use Cms\Core\Services\Webhooks\WebhookInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued processing of one stored webhook receipt (used when `core.webhooks.mode` is "queue").
 */
class ProcessWebhookReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param int $receiptId Webhook receipt id.
     */
    public function __construct(public readonly int $receiptId)
    {
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    /**
     * Process the receipt unless a previous attempt already did.
     */
    public function handle(WebhookInbox $inbox): void
    {
        $status = WebhookReceipt::query()->whereKey($this->receiptId)->value('status');

        if ($status === null || $status === WebhookReceipt::STATUS_PROCESSED) {
            return;
        }

        $inbox->process($this->receiptId);
    }
}
