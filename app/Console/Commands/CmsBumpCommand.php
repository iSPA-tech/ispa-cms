<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Bump a CalVer version: the release (`VERSION`) or, with --module, one module (`cms/<Module>/module.json`, plus its
 * composer.json when that carries a version).
 *
 * CalVer `YYYY.MM.NN`: year, two-digit month, two-digit counter of that month's releases. The next
 * version is NN + 1 when the current one is from this month, else `<this year>.<this month>.01`. The release and each
 * module count on their own; a release bump never writes module.json. The `type` argument (patch, minor, major) is
 * kept for older scripts: every type gives the next CalVer.
 */
class CmsBumpCommand extends Command
{
    /** @var string */
    protected $signature = 'cms:bump
        {type=patch : Kept for older scripts; patch, minor and major all give the next CalVer}
        {--module= : Bump this module (cms/<Module>/module.json) instead of the release VERSION}
        {--dry-run : Preview changes without writing files}
        {--base= : Root directory treated as the project base (VERSION and cms/*/module.json|composer.json)}';

    /** @var string */
    protected $description = 'Bump the iSPA CMS release or one module to the next CalVer (YYYY.MM.NN)';

    /** A current version this command can read: YYYY.MM.N, an older one-digit counter included. */
    private const READABLE = '/^(\d{4})\.(\d{2})\.(\d+)$/';

    /** @var string Root directory used for every path this run reads or writes. */
    private string $base = '';

    /**
     * Execute the console command.
     *
     * Writes are refused while the test suite runs unless an explicit --base
     * points at a throwaway tree, so tests can never stamp the tracked repo.
     */
    public function handle(): int
    {
        $baseOption = $this->option('base');
        $this->base = is_string($baseOption) && $baseOption !== '' ? rtrim($baseOption, '/') : base_path();

        if (app()->runningUnitTests() && ! $this->option('dry-run') && ! (is_string($baseOption) && $baseOption !== '')) {
            $this->error('Refusing to write version files while tests run; pass --base=<temp tree>.');

            return self::FAILURE;
        }

        if (! in_array($this->argument('type'), ['patch', 'minor', 'major'], true)) {
            $this->error("Invalid bump type: {$this->argument('type')}. Use: patch, minor, or major");

            return self::FAILURE;
        }

        $module = $this->option('module');

        return is_string($module) && $module !== '' ? $this->bumpModule($module) : $this->bumpRelease();
    }

    private function bumpRelease(): int
    {
        $versionFile = $this->base . '/VERSION';

        if (! File::exists($versionFile)) {
            $this->error('VERSION file not found at project root.');

            return self::FAILURE;
        }

        $current = trim(File::get($versionFile));
        $next = $this->next($current);

        if ($next === null) {
            $this->error("Invalid CalVer format: {$current}. Expected YYYY.MM.NN (e.g., 2026.11.01)");

            return self::FAILURE;
        }

        $prefix = $this->option('dry-run') ? '[dry-run] ' : '';
        $this->info("{$prefix}Version bump: {$current} -> {$next}");

        if ($this->option('dry-run')) {
            $this->line('  Would update: VERSION');

            return self::SUCCESS;
        }

        File::put($versionFile, $next);
        $this->line('  Updated: VERSION');
        $this->line("  Next: tag the release {$next}");
        $this->warn('  Remember: base-admin/VERSION follows the release by hand.');

        return self::SUCCESS;
    }

    private function bumpModule(string $module): int
    {
        $manifest = $this->base . '/cms/' . $module . '/module.json';

        if (! File::exists($manifest)) {
            $this->error("Module {$module} not found (no cms/{$module}/module.json).");

            return self::FAILURE;
        }

        $text = File::get($manifest);
        $current = (string) (json_decode($text, true)['version'] ?? '');
        $next = $current === '' ? $this->firstOfThisMonth() : $this->next($current);

        if ($next === null) {
            $this->error("Invalid CalVer format: {$current}. Expected YYYY.MM.NN (e.g., 2026.11.01)");

            return self::FAILURE;
        }

        $prefix = $this->option('dry-run') ? '[dry-run] ' : '';
        $this->info("{$prefix}Version bump: {$module} " . ($current === '' ? '(none)' : $current) . " -> {$next}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        File::put($manifest, $this->withVersion($text, $next));
        $this->line("  Updated: cms/{$module}/module.json");

        $composer = $this->base . '/cms/' . $module . '/composer.json';
        if (File::exists($composer) && array_key_exists('version', (array) json_decode(File::get($composer), true))) {
            File::put($composer, $this->withVersion(File::get($composer), $next));
            $this->line("  Updated: cms/{$module}/composer.json");
        }

        return self::SUCCESS;
    }

    /** The next CalVer after $current, or null when $current is not readable. */
    private function next(string $current): ?string
    {
        if (preg_match(self::READABLE, $current, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . $m[2] === now()->format('Y.m')
            ? sprintf('%s.%s.%02d', $m[1], $m[2], (int) $m[3] + 1)
            : $this->firstOfThisMonth();
    }

    private function firstOfThisMonth(): string
    {
        return now()->format('Y.m') . '.01';
    }

    /** Replace the top-level "version" value of a JSON file's text, keeping its layout. */
    private function withVersion(string $text, string $version): string
    {
        $updated = preg_replace('/^(\s*"version"\s*:\s*")[^"]*(")/m', '${1}' . $version . '${2}', $text, 1, $count);

        if ($count === 1 && is_string($updated)) {
            return $updated;
        }

        $data = json_decode($text, true);
        $data['version'] = $version;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
