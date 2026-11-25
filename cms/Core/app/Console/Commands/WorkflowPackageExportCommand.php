<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Illuminate\Console\Command;

/**
 * Export one workflow as a workflow package (`ispa-workflow-package` v1).
 *
 * Usage: php artisan cms:workflow-package-export church-stories --output=storage/app/church-stories.workflow.json
 *        [--sample=sample.json]   (a JSON object `{root: {...}}` used by the receiving site's dry run)
 * Without --output the package is printed. The package is checked as a receiving site would (without dry run); a
 * package with problems is not written and the command fails.
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowPackageExportCommand extends Command
{
    protected $signature = 'cms:workflow-package-export
        {slug : Workflow slug}
        {--output= : File to write}
        {--sample= : JSON file with the sample record ({"root": {...}})}';

    protected $description = 'Export one workflow as a workflow package (site-local references become $bind: placeholders).';

    public function handle(WorkflowPackageExporter $exporter, WorkflowPackageInspector $inspector): int
    {
        $sample = null;
        if (is_string($this->option('sample')) && $this->option('sample') !== '') {
            $sample = json_decode((string) @file_get_contents((string) $this->option('sample')), true);
            if (!is_array($sample)) {
                $this->error('The sample file must hold a JSON object.');

                return self::FAILURE;
            }
        }

        try {
            $package = $exporter->export((string) $this->argument('slug'), $sample);
        } catch (WorkflowPackageException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = $inspector->inspect($package, null, [], null, null, false);
        if (!$report['valid']) {
            foreach ($report['problems'] as $problem) {
                $this->error("{$problem['code']}: {$problem['message']}");
            }

            return self::FAILURE;
        }

        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $output = (string) $this->option('output');
        if ($output === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents($output, $json);
        $this->info('Wrote ' . WorkflowPackageFormat::FORMAT . " {$output} (" . count($package['manifest']['bindings']) . ' bindings).');

        return self::SUCCESS;
    }
}
