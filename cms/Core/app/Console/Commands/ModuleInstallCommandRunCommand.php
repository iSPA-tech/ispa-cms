<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Runs one declared `install.artisan` command of a module, only when artisan has a command registered under exactly
 * that name (F2).
 *
 * `php artisan <name>` resolves abbreviations, so a declared `cms:journal:x` would run any single command matching
 * `cms*:journal*:x*`, a command of another module included. The installer therefore never hands a declared name to
 * artisan: it runs this command in a fresh process (where the newly installed module is loaded), which re-checks the
 * module namespace rule ({@see ModuleInstaller::isInstallCommand()}), looks the name up exactly (no abbreviation, and
 * an alias of another command does not count) and runs it without arguments and without interaction.
 */
class ModuleInstallCommandRunCommand extends Command
{
    /** Command name the installer runs. */
    public const NAME = 'cms:module-install-command';

    protected $signature = 'cms:module-install-command
        {module : Module name, e.g. Journal}
        {declared : Declared install command, e.g. cms:journal:sync-permission}';

    protected $description = 'Run one declared module install command, only when it is registered under exactly that name.';

    /**
     * Run the declared command; FAILURE when it is outside the module namespace or not registered under that exact name,
     * else the command's own exit code.
     */
    public function handle(): int
    {
        $module = (string) $this->argument('module');
        $name = (string) $this->argument('declared');
        if (! ModuleInstaller::isInstallCommand($module, $name)) {
            $this->error('Refused: ' . $name . ' is not an install command of module ' . $module . '.');

            return self::FAILURE;
        }

        $application = $this->getApplication();
        $target = $application !== null && $application->has($name) ? $application->get($name) : null;
        if ($target === null || $target->getName() !== $name) {
            $this->error('Refused: no command is registered under exactly the name ' . $name . '.');

            return self::FAILURE;
        }

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return $target->run($input, $this->output);
    }
}
