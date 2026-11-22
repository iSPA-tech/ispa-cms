<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Cms\Core\Services\UploadR2SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncUploadsToR2Command extends Command
{
    protected $signature = 'upload:sync-to-r2 {--limit=200} {--max-runtime=300} {--dry-run}';

    protected $description = 'Sync pending upload_r2_objects rows to R2 in de-duplicated, bucketed priority order';

    public function handle(UploadR2SyncService $service): int
    {
        $lock = Cache::lock('upload:sync-to-r2', 600);
        if (!$lock->get()) {
            $this->error('Another sync-to-r2 run is already in progress. Exiting.');
            return self::FAILURE;
        }

        try {
            $startedAt = microtime(true);
            $maxRuntime = (int) $this->option('max-runtime');
            $objects = $service->pendingObjectsInPriorityOrder((int) $this->option('limit'));

            if ($this->option('dry-run')) {
                $this->info($objects->count() . ' objects would be synced (dry run, nothing written).');
                return self::SUCCESS;
            }

            $unsuccessful = 0;
            $bar = $this->output->createProgressBar($objects->count());

            foreach ($objects as $row) {
                if ((microtime(true) - $startedAt) > $maxRuntime) {
                    $this->warn('Max runtime reached, stopping cleanly — re-run to continue.');
                    break;
                }

                $object = UploadR2Object::find($row->id);
                $service->syncObject($object);

                if (in_array($object->fresh()->status, ['failed', 'missing_source'], true)) {
                    $unsuccessful++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            // Cutover marker: uploads whose full object set is now on R2
            // start serving from the CDN URL immediately.
            $flipped = $service->markFullySyncedUploads();
            if ($flipped > 0) {
                $this->info("{$flipped} upload(s) flipped to CDN serving.");
            }

            if ($unsuccessful > 0) {
                $this->error("{$unsuccessful} object(s) unsuccessful (failed or missing_source) — check upload_r2_objects.last_error, or run upload:r2-failures");
                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
