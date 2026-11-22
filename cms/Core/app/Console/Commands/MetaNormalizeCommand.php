<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\MetaNormalizationService;
use Illuminate\Console\Command;

class MetaNormalizeCommand extends Command
{
    protected $signature = 'cms:meta-normalize {--dry-run : Report what would change without writing}';
    protected $description = 'Deduplicate the meta value pool, remap references, and remove duplicate assignments.';

    public function handle(MetaNormalizationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $service->normalize($dryRun);

        $this->info($dryRun ? 'Meta normalization DRY RUN report:' : 'Meta normalization report:');
        foreach ($report as $step => $result) {
            $this->line(sprintf('  %-45s %s', $step, json_encode($result)));
        }

        return self::SUCCESS;
    }
}
