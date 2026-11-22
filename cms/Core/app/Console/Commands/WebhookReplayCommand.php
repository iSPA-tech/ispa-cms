<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\WebhookReceipt;
use Cms\Core\Services\Webhooks\WebhookInbox;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-dispatches stored webhook receipts. Needs --id, --failed or --since; the provider argument only narrows.
 * Processed receipts are replayed only when selected by --id.
 */
class WebhookReplayCommand extends Command
{
    protected $signature = 'cms:webhook-replay {provider? : Only this provider} {--id=* : Receipt ids} {--failed : Failed receipts} {--since= : Receipts received since this date/time (not processed ones)}';

    protected $description = 'Replay stored webhook receipts through their handlers.';

    /**
     * Select receipts and dispatch each one, printing a summary.
     */
    public function handle(WebhookInbox $inbox): int
    {
        $ids = array_filter((array) $this->option('id'));
        $since = (string) $this->option('since');

        if ($ids === [] && ! $this->option('failed') && $since === '') {
            $this->error('Refusing to run without a filter: pass --id, --failed or --since.');

            return self::INVALID;
        }

        $query = WebhookReceipt::query()->orderBy('id');

        if ($this->argument('provider')) {
            $query->where('provider', $this->argument('provider'));
        }

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('status', '!=', WebhookReceipt::STATUS_PROCESSED);
            $this->option('failed') && $query->where('status', WebhookReceipt::STATUS_FAILED);
            $since !== '' && $query->where('received_at', '>=', $since);
        }

        $ok = 0;
        $failed = 0;

        foreach ($query->get() as $receipt) {
            try {
                $inbox->dispatch($receipt);
                $ok++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->info("Replayed {$ok} receipt(s), {$failed} failed again.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
