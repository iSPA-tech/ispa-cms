<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Scans the codebase for raw Eloquent status query patterns and produces a Markdown report.
 *
 * Searches all PHP files in the app and cms module directories for patterns such as
 * ->where('status', 'published'), ->whereStatus(...), raw SQL status comparisons, and
 * attribute-level status string comparisons. Findings are categorised and written to a
 * Markdown file in storage/logs/. Run without --after for a baseline snapshot and again
 * with --after after refactoring to compare the before/after state.
 *
 * Usage: php artisan cms:status-scan
 * Options:
 *   --after  Generate an after-report instead of the baseline report
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StatusScanCommand extends Command
{
    protected $signature = 'cms:status-scan
                            {--after : Generate after-report instead of baseline}';

    protected $description = 'Scan codebase for raw status query patterns and produce a markdown report';

    /** @var array<string, list<array{file: string, line: int, snippet: string, suggestion: string}>> */
    protected array $findings = [
        'query_usages' => [],
        'attribute_comparisons' => [],
        'raw_sql' => [],
        'ambiguous' => [],
    ];

    protected array $patterns = [
        // Eloquent where with literal status values
        [
            'regex' => '/->where\(\s*[\'"]status[\'"]\s*,\s*[\'"](?:published|draft|pending|archived)[\'"]\s*\)/',
            'category' => 'query_usages',
            'suggestion' => 'Use ->published() / ->draft() / ->pending() / ->archived() scope',
        ],
        // Eloquent where with Constant::STATUS_*
        [
            'regex' => '/->where\(\s*[\'"]status[\'"]\s*,\s*(?:Constant|AppConstant)::STATUS_(?:PUBLISHED|DRAFT|PENDING|ARCHIVED)\s*\)/',
            'category' => 'query_usages',
            'suggestion' => 'Use ->published() / ->draft() / ->pending() / ->archived() scope',
        ],
        // ->whereStatus('value') or ->whereStatus(Constant::STATUS_*)
        [
            'regex' => '/->whereStatus\(\s*(?:[\'"](?:published|draft|pending|archived)[\'"]|(?:Constant|AppConstant)::STATUS_(?:PUBLISHED|DRAFT|PENDING|ARCHIVED)|StatusEnum::\w+(?:->value)?)\s*\)/',
            'category' => 'query_usages',
            'suggestion' => 'Use ->published() / ->draft() / ->pending() / ->archived() scope',
        ],
        // Table-prefixed: ->where('table.status', ...)
        [
            'regex' => '/->where\(\s*[\'"][\w]+\.status[\'"]\s*,/',
            'category' => 'ambiguous',
            'suggestion' => 'Table-prefixed status query — review manually (scopes cannot handle table prefix)',
        ],
        // whereIn with status values
        [
            'regex' => '/->whereIn\(\s*[\'"]status[\'"]\s*,/',
            'category' => 'ambiguous',
            'suggestion' => 'whereIn status — use ->whereStatus([...]) if all values are StatusEnum',
        ],
        // DB::table raw queries with status
        [
            'regex' => '/DB::table\(.+->where\(\s*[\'"]status[\'"]/',
            'category' => 'raw_sql',
            'suggestion' => 'DB::table query — cannot use Eloquent scopes',
        ],
        // Raw SQL with status
        [
            'regex' => '/(?:whereRaw|DB::raw|DB::select|DB::statement)\(.*status/',
            'category' => 'raw_sql',
            'suggestion' => 'Raw SQL — cannot use Eloquent scopes',
        ],
        // Dynamic variable status comparison
        [
            'regex' => '/->where\(\s*[\'"]status[\'"]\s*,\s*\$/',
            'category' => 'attribute_comparisons',
            'suggestion' => 'Dynamic status variable — use ->whereStatus($var) for type safety',
        ],
        // Attribute comparison ($model->status === or == )
        [
            'regex' => '/->status\s*[!=]==?\s*[\'"](?:published|draft|pending|archived)[\'"]/',
            'category' => 'attribute_comparisons',
            'suggestion' => 'Consider comparing with StatusEnum case instead of string literal',
        ],
    ];

    /**
     * Execute the console command.
     *
     * Scans all PHP files across the configured directories, collects findings by category,
     * and writes a Markdown report to storage/logs/.
     *
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $this->info('Scanning for raw status query patterns...');

        $directories = $this->getDirectories();
        $fileCount = 0;

        foreach ($directories as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->name('*.php')
                ->in($dir)
                ->notPath('vendor')
                ->notPath('node_modules')
                ->notPath('projects');

            foreach ($finder as $file) {
                $fileCount++;
                $this->scanFile($file->getRealPath());
            }
        }

        $totalFindings = collect($this->findings)->flatten(1)->count();
        $this->info("Scanned {$fileCount} PHP files, found {$totalFindings} status-related patterns.");

        $report = $this->generateReport();

        $filename = $this->option('after')
            ? 'status-usage-report-after.md'
            : 'status-usage-report.md';

        $outputPath = storage_path("logs/{$filename}");
        File::put($outputPath, $report);

        $this->info("Report written to: {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * Return the list of directory paths to scan for PHP files.
     *
     * @return array<int, string> Absolute directory paths
     */
    protected function getDirectories(): array
    {
        $base = base_path();

        return [
            "{$base}/app",
            "{$base}/cms/Core/app",
            "{$base}/cms/Content/app",
            "{$base}/cms/Product/app",
            "{$base}/cms/Education/app",
            "{$base}/cms/Ecommerce/app",
            "{$base}/cms/RealEstate/app",
        ];
    }

    /**
     * Scan a single PHP file line by line against all registered patterns.
     *
     * @param string $filePath Absolute path to the PHP file to scan
     * @return void
     */
    protected function scanFile(string $filePath): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $lineNum => $line) {
            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern['regex'], $line)) {
                    $this->findings[$pattern['category']][] = [
                        'file' => $this->relativePath($filePath),
                        'line' => $lineNum + 1,
                        'snippet' => trim($line),
                        'suggestion' => $pattern['suggestion'],
                    ];
                }
            }
        }
    }

    /**
     * Strip the project base path prefix from an absolute file path.
     *
     * @param string $path Absolute file path
     * @return string Relative path from the project root
     */
    protected function relativePath(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }

    /**
     * Build the Markdown report string from the collected findings.
     *
     * @return string Markdown-formatted report content
     */
    protected function generateReport(): string
    {
        $total = collect($this->findings)->flatten(1)->count();
        $now = now()->format('Y-m-d H:i:s');
        $type = $this->option('after') ? 'After Refactor' : 'Baseline';

        $md = "# Status Usage Report ({$type})\n\n";
        $md .= "Generated: {$now}\n\n";
        $md .= "## Summary\n\n";
        $md .= "| Category | Count |\n";
        $md .= "|----------|-------|\n";

        foreach ($this->findings as $category => $items) {
            $label = str_replace('_', ' ', ucfirst($category));
            $md .= "| {$label} | " . count($items) . " |\n";
        }

        $md .= "| **Total** | **{$total}** |\n\n";

        foreach ($this->findings as $category => $items) {
            if (empty($items)) {
                continue;
            }

            $label = str_replace('_', ' ', ucfirst($category));
            $md .= "## {$label}\n\n";

            foreach ($items as $item) {
                $md .= "- **{$item['file']}:{$item['line']}**\n";
                $md .= "  ```php\n  {$item['snippet']}\n  ```\n";
                $md .= "  > {$item['suggestion']}\n\n";
            }
        }

        return $md;
    }
}
