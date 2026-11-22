<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\StarterPacks\PackContentScanner;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackExporter;
use Illuminate\Console\Command;

/**
 * Builds a starter pack folder from this (reference) site: configuration, roles and sample content.
 *
 * Refuses to overwrite an existing pack unless `--force` is given, and writes nothing when the export would
 * carry a secret or personal data.
 */
class StarterPackExportCommand extends Command
{
    protected $signature = 'cms:starter-pack-export {key : Pack key (lowercase letters, digits, hyphens)} {--modules=* : Module to include (repeat or comma separated; Core is always included)} {--roles=* : Role name to include (repeat or comma separated)} {--out= : Pack folder to write (default: cms/Core/resources/starter-packs/<key>)} {--force : Replace an existing pack} {--modules-path= : Modules folder (default: base_path(\'cms\'))}';

    protected $description = 'Export a starter pack (configuration, roles, sample content) from this site.';

    /**
     * Run the export and print what went into the pack.
     *
     * @return int 1 when the export was refused or failed, otherwise 0.
     */
    public function handle(StarterContentRegistry $registry): int
    {
        $key = (string) $this->argument('key');
        $out = (string) ($this->option('out') ?: base_path('cms/Core/resources/starter-packs/' . $key));
        $exporter = new StarterPackExporter($registry, new PackContentScanner(), $this->option('modules-path') ?: null);

        try {
            $result = $exporter->export($key, $this->listOption('modules'), $this->listOption('roles'), $out, (bool) $this->option('force'));
        } catch (StarterPackException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Pack "' . $key . '" written to ' . $result['dir']);
        $this->line('  modules: ' . implode(', ', $result['modules']));
        $this->line(sprintf('  configuration: %d settings, %d table rows', $result['settings'], $result['tables']));
        foreach ($result['skipped_settings'] as $reason => $count) {
            $this->line(sprintf('  left out: %d %s setting(s)', $count, $reason));
        }
        $this->line('  roles: ' . ($result['roles'] === [] ? 'none' : implode(', ', $result['roles'])));
        $this->line('  content: ' . ($result['content'] === [] ? 'none' : implode(', ', $result['content'])));
        foreach ($result['skipped'] as $id) {
            $this->line('  <fg=yellow>skipped</> importer "' . $id . '" cannot export on this site.');
        }

        return self::SUCCESS;
    }

    /**
     * Values of a repeatable option; commas also separate values.
     *
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $values = [];
        foreach ((array) $this->option($name) as $value) {
            foreach (explode(',', (string) $value) as $part) {
                if (trim($part) !== '') {
                    $values[] = trim($part);
                }
            }
        }

        return $values;
    }
}
