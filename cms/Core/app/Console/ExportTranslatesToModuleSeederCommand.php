<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Illuminate\Console\Command;
use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Services\TranslationModuleSeederExporter;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Export current translates table rows into per-module seeder PHP files
 * at cms/<Module>/database/seeders/sql/translate.php based on the `modules` column.
 * @package Cms\Core\Console
 */
class ExportTranslatesToModuleSeederCommand extends Command
{
    protected $signature = 'cms:translation-export-modules'
    . ' {--only=* : Limit export to specific module names (case-insensitive). Can be passed multiple times}';

    protected $description = 'Export translates by modules into each module\'s database/seeders/sql/translate.php file.';

    /**
     * Initialize the command with the translate repository and module exporter.
     *
     * @param TranslateInterface              $translateRepo  Repository for querying translation rows.
     * @param TranslationModuleSeederExporter $moduleExporter Exporter for writing per-module PHP seeder files.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected TranslateInterface $translateRepo,
        protected TranslationModuleSeederExporter $moduleExporter
    ) {
        parent::__construct();
    }

    /**
     * Export translation rows from the database into per-module PHP seeder files.
     *
     * Groups translations by their `modules` column and writes each group to the
     * corresponding module's database/seeders/sql/translate.php file. Supports --only
     * to restrict export to specific modules.
     *
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $this->info('Exporting translates into per-module seeder files...');

        // Fetch translates; keep ordering stable for diff-friendly output
        /** @var Collection $translates */
        $translates = $this->translateRepo->get([
            'order' => [
                'group_name' => 'ASC',
                'name' => 'ASC',
            ],
        ]);

        if ($translates->isEmpty()) {
            $this->warn('No translates found.');
            return CommandAlias::SUCCESS;
        }

        $only = collect((array)$this->option('only'))
            ->filter()
            ->map(fn($m) => strtolower((string)$m))
            ->unique()
            ->values();

        try {
            $summary = $this->moduleExporter->export($translates, $only->all());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return CommandAlias::FAILURE;
        }

        if (empty($summary['modules'])) {
            $this->warn('No module-targeted translates to export (after filtering).');
            return CommandAlias::SUCCESS;
        }

        foreach ($summary['modules'] as $moduleSummary) {
            $this->line("Wrote {$moduleSummary['records']} records to {$moduleSummary['file']}");
        }

        if (!empty($summary['skipped'])) {
            foreach ($summary['skipped'] as $skipped) {
                $this->warn("Module folder not found for '{$skipped}', skipping...");
            }
        }

        $this->info('Export done. Updated ' . count($summary['modules']) . ' module file(s).');
        return CommandAlias::SUCCESS;
    }
}
