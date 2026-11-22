<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Ai\Usage\AiCallLog;
use Illuminate\Console\Command;

/**
 * Deletes AI call log rows older than the retention window.
 */
class AiCallLogPruneCommand extends Command
{
    protected $signature = 'cms:ai-call-log-prune {--days= : Retention in days (default core.ai_usage.trace.retention_days)}';

    protected $description = 'Delete AI call log rows older than the retention window.';

    /**
     * Prune and print the number of deleted rows.
     *
     * @param AiCallLog $log Call log service.
     * @return int Command exit code.
     */
    public function handle(AiCallLog $log): int
    {
        $option = $this->option('days');
        $days = is_numeric($option) ? (int) $option : (int) config('core.ai_usage.trace.retention_days', 14);

        $this->info(sprintf('Deleted %d AI call log row(s) older than %d day(s).', $log->prune($days), max(0, $days)));

        return self::SUCCESS;
    }
}
