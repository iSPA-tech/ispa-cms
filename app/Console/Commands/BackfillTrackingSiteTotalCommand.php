<?php

namespace App\Console\Commands;

use App\Services\Tracking\TrackingAggregateReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class BackfillTrackingSiteTotalCommand extends Command
{
    protected $signature = 'cms:tracking-backfill-site-total
                            {--checkpoint-id= : Stable backup/checkpoint identifier}
                            {--max-id= : Fixed maximum tracks ID}
                            {--after-id=0 : Resume after this tracks ID}
                            {--limit=100000 : Maximum rows to scan}
                            {--output= : Optional manifest output path}';

    protected $description = 'Build a bounded dry-run site baseline manifest';

    public function handle(TrackingAggregateReconciler $reconciler): int
    {
        return $this->build($reconciler, 'tracking:site-baseline');
    }

    protected function build(TrackingAggregateReconciler $reconciler, string $lockName): int
    {
        $checkpointId = trim((string) $this->option('checkpoint-id'));
        if ($checkpointId === '' || $this->option('max-id') === null) {
            $this->error('--checkpoint-id and --max-id are required.');

            return self::FAILURE;
        }

        $lock = Cache::lock($lockName, 600);
        if (!$lock->get()) {
            $this->error('Baseline scan is already running.');

            return self::FAILURE;
        }

        try {
            $manifest = $reconciler->buildBaselineManifest(
                $checkpointId,
                max(0, (int) $this->option('max-id')),
                max(0, (int) $this->option('after-id')),
                max(1, (int) $this->option('limit'))
            );
            $this->line(implode(' ', [
                'rows_through=' . $manifest['range_last_id'],
                'site_views=' . data_get($manifest, 'site.raw_views'),
                'site_baseline=' . data_get($manifest, 'site.baseline_views'),
                'targets=' . $manifest['target_count'],
                'unresolved=' . $manifest['unresolved_count'],
                'unresolved_views=' . $manifest['unresolved_views'],
                'negative=' . $manifest['negative_baseline_count'],
                'complete=' . ($manifest['complete'] ? 'yes' : 'no'),
                'checksum=' . $manifest['checksum'],
            ]));

            $output = trim((string) $this->option('output'));
            if ($output !== '') {
                File::ensureDirectoryExists(dirname($output));
                File::put(
                    $output,
                    json_encode(
                        $manifest,
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                    ) . PHP_EOL
                );
            }

            return $manifest['negative_baseline_count'] === 0
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
