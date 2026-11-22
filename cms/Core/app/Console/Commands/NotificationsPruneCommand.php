<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes old in-app admin notifications: read rows after N days and unread rows after M days.
 */
class NotificationsPruneCommand extends Command
{
    protected $signature = 'cms:notifications-prune {--read-days=30 : Retention in days for read notifications} {--unread-days=90 : Retention in days for unread notifications}';

    protected $description = 'Delete read notifications older than N days and unread notifications older than M days.';

    /**
     * Delete old rows in batches and print both counts.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        $readDays = (int) $this->option('read-days');
        $unreadDays = (int) $this->option('unread-days');

        if ($readDays < 1 || $unreadDays < 1) {
            $this->error('Both --read-days and --unread-days must be at least 1.');

            return self::FAILURE;
        }

        $read = $this->prune(static fn ($q) => $q->whereNotNull('read_at')->where('read_at', '<', now()->subDays($readDays)));
        $unread = $this->prune(static fn ($q) => $q->whereNull('read_at')->where('created_at', '<', now()->subDays($unreadDays)));

        $this->info(sprintf('Deleted %d read notification(s) older than %d day(s).', $read, $readDays));
        $this->info(sprintf('Deleted %d unread notification(s) older than %d day(s).', $unread, $unreadDays));

        return self::SUCCESS;
    }

    /**
     * Delete the rows matched by the scope in batches of 1000 ids.
     *
     * @param callable $scope Applies the where clauses to the query.
     * @return int Number of deleted rows.
     */
    private function prune(callable $scope): int
    {
        $total = 0;

        do {
            $ids = $scope(DB::table('notifications'))->limit(1000)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $total += DB::table('notifications')->whereIn('id', $ids)->delete();
        } while (count($ids) === 1000);

        return $total;
    }
}
