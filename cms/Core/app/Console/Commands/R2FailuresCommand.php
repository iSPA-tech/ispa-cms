<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;

/**
 * Report failed / missing_source replication rows. Quarantined
 * (content_changed) rows are listed separately and only ever resolved via
 * upload:r2-approve-change.
 */
class R2FailuresCommand extends Command
{
    protected $signature = 'upload:r2-failures {--limit=50}';
    protected $description = 'List failed and missing_source R2 replication rows.';

    public function handle(): int
    {
        $rows = UploadR2Object::query()
            ->whereIn('status', ['failed', 'missing_source'])
            ->orderByDesc('updated_at')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'relative_path', 'status', 'attempts', 'last_error']);

        foreach ($rows as $row) {
            $this->line(sprintf(
                '#%d [%s] attempts=%d %s — %s',
                $row->id,
                $row->status,
                $row->attempts,
                $row->relative_path,
                substr((string) $row->last_error, 0, 120),
            ));
        }

        $this->info(sprintf(
            'failed=%d missing_source=%d quarantined=%d',
            UploadR2Object::query()->where('status', 'failed')->count(),
            UploadR2Object::query()->where('status', 'missing_source')->count(),
            UploadR2Object::query()->where('status', 'content_changed')->count(),
        ));

        return self::SUCCESS;
    }
}
