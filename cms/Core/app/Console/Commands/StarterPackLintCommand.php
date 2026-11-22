<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\StarterPacks\PackContentScanner;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackLinter;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Illuminate\Console\Command;

/**
 * CI-style check of every starter pack: lists all errors and warnings, exits 1 on any error.
 */
class StarterPackLintCommand extends Command
{
    protected $signature = 'cms:starter-pack-lint {key? : Lint only this pack} {--path= : Folder with one sub-folder per pack (default: cms/Core/resources/starter-packs)} {--modules-path= : Modules folder (default: base_path(\'cms\'))}';

    protected $description = 'Check starter packs: manifest, modules, configuration package, role permissions, importers and secrets.';

    /**
     * Lint the packs and print a grouped report.
     *
     * @return int 1 when any error was found, otherwise 0.
     */
    public function handle(StarterContentRegistry $registry): int
    {
        $root = rtrim((string) ($this->option('path') ?: base_path('cms/Core/resources/starter-packs')), '/\\');
        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $key = $this->argument('key');
        if (is_string($key) && $key !== '') {
            $dirs = array_values(array_filter($dirs, static fn (string $d): bool => basename($d) === $key));
            if ($dirs === []) {
                $this->line(sprintf('  <fg=red>error</>   no pack folder "%s" under %s', $key, $root));

                return 1;
            }
        }

        if ($dirs === []) {
            $this->line('no packs');

            return 0;
        }

        $linter = new StarterPackLinter(
            new StarterPackLoader($root),
            new PackageValidator(),
            new PackContentScanner(),
            $registry,
            $this->option('modules-path') ?: null,
        );

        $errors = 0;
        $warnings = 0;
        foreach ($dirs as $dir) {
            $result = $linter->lint($dir);
            $this->line(basename($dir));
            foreach ($result['errors'] as $message) {
                $this->line('  <fg=red>error</>   ' . $message);
            }
            foreach ($result['warnings'] as $message) {
                $this->line('  <fg=yellow>warning</> ' . $message);
            }
            if ($result['errors'] === [] && $result['warnings'] === []) {
                $this->line('  ok');
            }
            $errors += count($result['errors']);
            $warnings += count($result['warnings']);
        }

        $this->line(sprintf('%d packs, %d errors, %d warnings', count($dirs), $errors, $warnings));

        return $errors > 0 ? 1 : 0;
    }
}
