<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;

/**
 * Replication health metrics. "Not hash-verified in >35 days" is the
 * guardrail that catches the weekly hash rotation silently breaking.
 */
class R2HealthCommand extends Command
{
    protected $signature = 'upload:r2-health';
    protected $description = 'Show R2 replication pipeline health metrics.';

    public function handle(): int
    {
        $this->info('Pending: ' . UploadR2Object::where('status', 'pending')->count());
        $this->info('Failed: ' . UploadR2Object::where('status', 'failed')->count());
        $this->info('Missing source: ' . UploadR2Object::where('status', 'missing_source')->count());
        $this->info('Quarantined (content_changed): ' . UploadR2Object::where('status', 'content_changed')->count());
        $oldestPending = UploadR2Object::where('status', 'pending')->min('created_at');
        $this->info('Oldest pending age: ' . ($oldestPending ? now()->diffForHumans($oldestPending, true) : 'n/a'));
        $this->info('Verified total: ' . UploadR2Object::where('status', 'verified')->count());
        $this->info('Verified in last 24h: ' . UploadR2Object::where('verified_at', '>=', now()->subDay())->count());
        $this->info('Not hash-verified in >35 days: ' . UploadR2Object::whereIn('status', ['synced', 'verified'])
            ->where(fn ($q) => $q->whereNull('verified_at')->orWhere('verified_at', '<', now()->subDays(35)))
            ->count());
        $this->info('Evicted locally: ' . UploadR2Object::whereNotNull('evicted_at')->count());
        $this->info('Total tracked bytes: ' . number_format((float) UploadR2Object::sum('source_size')));
        $this->info('Verified R2 bytes: ' . number_format((float) UploadR2Object::where('status', 'verified')->sum('source_size')));

        return self::SUCCESS;
    }
}
