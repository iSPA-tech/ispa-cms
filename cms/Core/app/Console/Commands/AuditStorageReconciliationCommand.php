<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\StorageReconciliationService;
use Illuminate\Console\Command;

class AuditStorageReconciliationCommand extends Command
{
    protected $signature = 'storage:audit-reconciliation {--csv-dir= : directory to write per-category CSV reports into}';

    protected $description = 'Read-only reconciliation of the filesystem, uploads table, and upload_target_pivot across all known upload roots';

    public function handle(StorageReconciliationService $service): int
    {
        $report = $service->reconcile();

        foreach ($report as $key => $rows) {
            $this->line(sprintf('%s: %d', $key, count($rows)));
        }

        if ($csvDir = $this->option('csv-dir')) {
            if (!is_dir($csvDir) && !mkdir($csvDir, 0775, true) && !is_dir($csvDir)) {
                $this->error("Could not create csv-dir: {$csvDir}");
                return self::FAILURE;
            }

            foreach ($report as $key => $rows) {
                $handle = fopen(rtrim($csvDir, '/') . "/{$key}.csv", 'w');
                foreach ($rows as $row) {
                    fputcsv($handle, [$row]);
                }
                fclose($handle);
            }

            $this->info("CSV reports written to {$csvDir}");
        }

        if (!empty($report['unexpected_new_roots'])) {
            $this->error('Unexpected new upload roots found — StorageReconciliationService::KNOWN_UPLOAD_ROOTS is stale: ' . implode(', ', $report['unexpected_new_roots']));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
