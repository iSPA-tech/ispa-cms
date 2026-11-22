<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;

/**
 * Reset retryable failures back to pending with exponential backoff.
 * Never touches content_changed rows — those exclusively go through
 * upload:r2-approve-change.
 */
class R2RetryCommand extends Command
{
    protected $signature = 'upload:r2-retry {--max-attempts=5} {--limit=500}';
    protected $description = 'Reset failed R2 replication rows (attempts below the cap) back to pending with backoff.';

    public function handle(): int
    {
        $reset = 0;

        UploadR2Object::query()
            ->where('status', 'failed')
            ->where('attempts', '<', (int) $this->option('max-attempts'))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (UploadR2Object $object) use (&$reset): void {
                $object->update([
                    'status' => 'pending',
                    'next_retry_at' => now()->addMinutes(2 ** (int) $object->attempts),
                ]);
                $reset++;
            });

        $this->info("Reset {$reset} failed row(s) back to pending.");

        return self::SUCCESS;
    }
}
