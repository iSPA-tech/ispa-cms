<?php

declare(strict_types=1);

namespace Cms\Core\Contracts;

/**
 * Processes one stored webhook event for a provider registered in the webhook inbox.
 *
 * Implementations must be safe to run again for the same payload (replay and queue retries).
 */
interface WebhookHandler
{
    /**
     * Handle the decoded webhook payload; throw to mark the receipt failed.
     *
     * @param array<string, mixed> $payload Decoded webhook body.
     */
    public function handle(array $payload): void;
}
