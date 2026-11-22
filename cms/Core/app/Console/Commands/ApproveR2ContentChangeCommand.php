<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;

class ApproveR2ContentChangeCommand extends Command
{
    protected $signature = 'upload:r2-approve-change {id? : specific upload_r2_objects id} {--all : approve every content_changed row}';
    protected $description = 'Review and approve a quarantined content change for re-sync to R2';

    public function handle(): int
    {
        $query = UploadR2Object::where('status', 'content_changed');

        if ($id = $this->argument('id')) {
            $query->where('id', $id);
        } elseif (!$this->option('all')) {
            $this->error('Specify an object id or pass --all.');
            return self::FAILURE;
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No content_changed rows match.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line("#{$row->id} {$row->relative_path}: previous_sha256={$row->previous_sha256} new_sha256={$row->source_sha256}");
        }

        if (!$this->confirm('Approve ' . $rows->count() . ' content change(s) for re-sync to R2?')) {
            return self::FAILURE;
        }

        $rows->each->update(['status' => 'pending', 'last_error' => null]);
        $this->info($rows->count() . ' object(s) approved and re-queued for sync.');
        return self::SUCCESS;
    }
}
