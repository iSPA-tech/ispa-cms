<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\WebhookReceipt;
use Illuminate\Console\Command;

/**
 * Deletes processed webhook receipts older than N days; failed receipts are kept.
 */
class WebhookPruneCommand extends Command
{
    protected $signature = 'cms:webhook-prune {--days=30 : Age in days}';

    protected $description = 'Delete processed webhook receipts older than the retention period.';

    /**
     * Delete old processed receipts and print the count.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = WebhookReceipt::query()
            ->where('status', WebhookReceipt::STATUS_PROCESSED)
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} processed webhook receipt(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
