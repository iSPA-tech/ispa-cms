<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Exports a configuration package from the command line.
 *
 * The export runs through the same job as the admin screen (on the configured queue connection). The requesting
 * user defaults to id 1; use --user to attribute the run to someone else. With --out the package JSON is written to
 * that file (the run must have finished, which is immediate on the sync queue); without it the run uuid is printed.
 */
class ConfigExportCommand extends Command
{
    protected $signature = 'cms:config-export
        {--sections=settings,translations,roles,menus,workflows,tables : Comma separated section names}
        {--translation-scope=web : Translation scope: web, adm, app or all}
        {--user=1 : Id of the user the run is attributed to}
        {--out= : File to write the package JSON to}';

    protected $description = 'Export a configuration package (settings, translations, roles, menus, workflows, tables).';

    /**
     * Run the export and report the result.
     *
     * @return int Command exit code.
     */
    public function handle(ConfigPackageExporter $exporter): int
    {
        $sections = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sections'))), 'strlen'));

        try {
            $run = $exporter->export($sections, ['translation_scope' => (string) $this->option('translation-scope')], (int) $this->option('user'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = (string) $this->option('out');

        if ($out === '') {
            $this->line((string) $run->uuid);

            return self::SUCCESS;
        }

        if ($run->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            $this->error($run->status === ConfigPackageRun::STATUS_FAILED
                ? 'Export failed (run ' . $run->uuid . ').'
                : 'Export is not finished yet (run ' . $run->uuid . ', status ' . $run->status . '); no file written.');

            return self::FAILURE;
        }

        $json = json_encode($run->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (@file_put_contents($out, $json) === false) {
            $this->error('Cannot write the package file.');

            return self::FAILURE;
        }

        $this->info('Package written (run ' . $run->uuid . ').');

        return self::SUCCESS;
    }
}
