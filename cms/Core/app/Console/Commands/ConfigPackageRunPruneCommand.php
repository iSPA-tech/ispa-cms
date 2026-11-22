<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\ConfigPackageRun;
use Illuminate\Console\Command;

/**
 * Clears the payload and plan of old config package runs while keeping the metadata row.
 */
class ConfigPackageRunPruneCommand extends Command
{
    protected $signature = 'cms:config-package-prune {--days=14 : Retention in days for payload and plan}';

    protected $description = 'Clear payload and plan of config package runs older than N days (the run row is kept).';

    /**
     * Null payload and plan of old runs in chunks and print the count.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $cleared = 0;

        do {
            $ids = ConfigPackageRun::query()
                ->where('created_at', '<', $cutoff)
                ->where(static function ($query): void {
                    $query->whereNotNull('payload')->orWhereNotNull('plan');
                })
                ->orderBy('id')
                ->limit(100)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            ConfigPackageRun::query()->whereIn('id', $ids)->update(['payload' => null, 'plan' => null]);
            $cleared += $ids->count();
        } while (true);

        $this->info(sprintf('Cleared payload and plan of %d config package run(s) older than %d day(s).', $cleared, $days));

        return self::SUCCESS;
    }
}
