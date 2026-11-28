<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeFinisher;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * The steps after a core update, run by the version just installed ({@see CoreUpgradeFinisher}). The updater calls it
 * once the new files are in place and reads the outcome from `--result`; it is not meant to be run by hand.
 */
class CoreUpgradeFinishCommand extends Command
{
    protected $signature = 'cms:core-upgrade-finish
        {--deploy : The site is a CI-deployed release (config and routes cached, full language files rebuilt)}
        {--result= : JSON file the outcome is written to (ok with warnings, or ok false with code and message)}';

    protected $description = 'Run the steps after a core update (migrations, permissions, caches, translations); called by the updater.';

    protected $hidden = true;

    public function handle(ProcessRunner $runner): int
    {
        try {
            $warnings = (new CoreUpgradeFinisher($runner))->run(base_path(), base_path(), (bool) $this->option('deploy'));
            $result = ['ok' => true, 'warnings' => $warnings];
        } catch (CoreUpgradeException $e) {
            $result = ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $result = ['ok' => false, 'code' => 'CORE_UPGRADE_FAILED', 'message' => 'The steps after the update failed: '.$e->getMessage()];
        }

        $path = (string) $this->option('result');
        if ($path !== '') {
            if (! str_ends_with($path, '.json') || ! is_dir(dirname($path)) || is_link($path)
                || @file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES)."\n") === false) {
                $this->components->error('Could not write the result file.');

                return self::FAILURE;
            }
            @chmod($path, 0600);
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }
        if ($result['ok'] !== true) {
            $this->components->error($result['message']);

            return self::FAILURE;
        }
        $this->components->info('Steps after the update done.');

        return self::SUCCESS;
    }
}
