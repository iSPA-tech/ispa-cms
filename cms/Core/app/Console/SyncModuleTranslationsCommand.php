<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Nwidart\Modules\Facades\Module;

/**
 * Import module translation seeders from cms/<Module>/database/seeders/sql/translate.php.
 *
 * Existing translation keys keep their text unless an authored admin sidecar defines different
 * wording, in which case the sidecar wins (see TranslationModuleSeederImporter); missing module
 * assignments are added.
 * @package Cms\Core\Console
 */
class SyncModuleTranslationsCommand extends Command
{
    /**
     * Initialize the command with the translation module seeder importer.
     *
     * @param TranslationModuleSeederImporter $importer Importer for module-specific translation seeder files.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        private readonly TranslationModuleSeederImporter $importer
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected $signature = 'cms:translation-sync {module? : Only import translations for the specified module}'
        . ' {--all : Include disabled modules while syncing}'
        . ' {--dry-run : Report keys that would be added, linked or reworded without writing}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Import translation seeders for CMS modules; existing keys only receive wording from authored admin sidecars.';

    /**
     * Import translation seeder files for CMS modules.
     *
     * Reads each module's database/seeders/sql/translate.php plus its translate-admin-*.php
     * sidecars, creates missing rows, merges module lists and overwrites differing wording from the sidecars.
     * Supports targeting a single module, including disabled modules via --all, and --dry-run.
     *
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $modules = $this->resolveTargetModules(
            $this->argument('module'),
            (bool) $this->option('all')
        );

        if ($modules->isEmpty()) {
            $this->warn('No matching CMS modules found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $modulePath = $module->getPath();

            $this->components->info("Importing translations for {$moduleName}");
            $this->reportTranslationImport(
                $moduleName,
                $this->importer->importFromModule($moduleName, $modulePath, $dryRun)
            );
        }

        $this->components->info($dryRun
            ? 'Dry run completed; no translation rows were written.'
            : 'Module translation import completed.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, \Nwidart\Modules\Laravel\Module>
     */
    private function resolveTargetModules(?string $requested, bool $includeAll): Collection
    {
        $pool = $includeAll ? Module::all() : Module::allEnabled();

        if ($requested === null) {
            return collect($pool)->values();
        }

        $needle = strtolower($requested);

        $matches = collect($pool)
            ->filter(static function ($module) use ($needle) {
                return strtolower($module->getName()) === $needle
                    || strtolower($module->getLowerName()) === $needle;
            })
            ->values();

        if ($matches->isEmpty()) {
            $module = Module::find($requested);

            return $module ? collect([$module]) : collect();
        }

        return $matches;
    }

    /**
     * Display translation import results.
     *
     * @param string $moduleName
     * @param array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated?: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     dry_run?: bool,
     *     rewords?: array<int, array<string, mixed>>,
     *     duplicates?: array<int, array{name: string, count: int}>
     * } $result
     */
    private function reportTranslationImport(string $moduleName, array $result): void
    {
        if (! $result['found']) {
            $this->components->twoColumnDetail('Translations', 'none found');

            return;
        }

        if (! $result['valid']) {
            $this->components->warn("Translations file for {$moduleName} must return an array.");

            return;
        }

        $updated = (int) ($result['updated'] ?? 0);
        $summary = "added {$result['created']}, linked {$result['linked']}, updated {$updated}";

        if ($result['skipped'] > 0) {
            $summary .= ", skipped {$result['skipped']}";
        }

        if ($result['dry_run'] ?? false) {
            $summary = "would be {$summary}";
        }

        $this->components->twoColumnDetail('Translations', $summary);

        foreach (TranslationModuleSeederImporter::describeRewords($result) as $line) {
            $this->line("  reword: {$line}");
        }

        foreach (TranslationModuleSeederImporter::describeDuplicates($result) as $line) {
            $this->components->warn($line);
        }
    }
}
