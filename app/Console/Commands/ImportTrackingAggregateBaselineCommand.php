<?php

namespace App\Console\Commands;

use App\Services\Tracking\TrackingAggregateReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class ImportTrackingAggregateBaselineCommand extends Command
{
    protected $signature = 'cms:tracking-import-baseline
                            {--manifest= : Verified manifest JSON path}
                            {--force : Persist verified baseline values}';

    protected $description = 'Verify or import a deterministic aggregate baseline manifest';

    public function handle(TrackingAggregateReconciler $reconciler): int
    {
        $path = trim((string) $this->option('manifest'));
        if ($path === '' || !File::isFile($path)) {
            $this->error('A readable --manifest file is required.');

            return self::FAILURE;
        }

        $lock = Cache::lock('tracking:baseline-import', 300);
        if (!$lock->get()) {
            $this->error('Baseline import is already running.');

            return self::FAILURE;
        }

        try {
            $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            $reconciler->verifyBaselineManifest($manifest);
            if (!$this->option('force')) {
                $this->line('Baseline manifest verified; dry-run only.');

                return self::SUCCESS;
            }

            $mutations = $reconciler->importBaselineManifest($manifest);
            $this->line("Baseline manifest imported; mutations={$mutations}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
