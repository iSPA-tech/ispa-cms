<?php

namespace Cms\Core\Database\Seeders\Concerns;

use Cms\Core\Services\TranslationModuleSeederImporter;

trait ReportsTranslationImport
{
    /**
     * Output translation import summary to the console (when available).
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
     *     rewords?: array<int, array<string, mixed>>,
     *     duplicates?: array<int, array{name: string, count: int}>
     * } $result
     */
    protected function reportTranslationImport(string $moduleName, array $result): void
    {
        if (! isset($this->command)) {
            return;
        }

        if (! $result['found']) {
            $this->command->warn("{$moduleName}: translations file not found.");

            return;
        }

        if (! $result['valid']) {
            $this->command->warn("{$moduleName}: translations file must return an array.");

            return;
        }

        $this->command->info(sprintf(
            '%s translations - added %d, linked %d, updated %d, skipped %d.',
            $moduleName,
            $result['created'],
            $result['linked'],
            (int) ($result['updated'] ?? 0),
            $result['skipped']
        ));

        foreach (TranslationModuleSeederImporter::describeRewords($result) as $line) {
            $this->command->line("  reword: {$line}");
        }

        foreach (TranslationModuleSeederImporter::describeDuplicates($result) as $line) {
            $this->command->warn($line);
        }
    }
}

