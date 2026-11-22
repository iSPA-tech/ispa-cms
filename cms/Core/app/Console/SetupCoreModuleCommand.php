<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Runs all registered Core module console commands in a single pass for activation or CI builds.
 *
 * Discovers all Artisan commands whose class name starts with the Cms\Core\Console\ namespace,
 * excludes itself and the first-run installer (cms:install), sorts them alphabetically, and runs each in sequence. Stops and returns
 * the failing exit code if any command exits with a non-zero code. Useful for bootstrapping
 * a fresh environment or running the full Core setup in one step during deployment.
 *
 * Usage: php artisan cms:core:setup
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SetupCoreModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cms:core:setup';

    /**
     * The console command description.
     */
    protected $description = 'Run all Core module console commands for activation or CI builds.';

    /**
     * Execute the console command.
     *
     * Discovers and runs all Core namespace commands in sorted order,
     * halting with the failing exit code on first failure.
     *
     * @return int Command::SUCCESS or the exit code of the first failing command
     */
    public function handle(): int
    {
        $namespacePrefix = 'Cms\\Core\\Console\\';
        $self = $this->getName() ?? $this->signature ?? 'cms:core:setup';

        $commands = collect(Artisan::all())
            ->filter(static function ($command, string $name) use ($namespacePrefix, $self) {
                return Str::startsWith(get_class($command), $namespacePrefix) && $name !== $self && $name !== 'cms:install';
            })
            ->keys()
            ->sort()
            ->values();

        if ($commands->isEmpty()) {
            $this->warn('No Core commands registered to run.');

            return Command::SUCCESS;
        }

        foreach ($commands as $commandName) {
            $this->line("Running {$commandName}...");
            $exitCode = $this->call($commandName);

            if ($exitCode !== Command::SUCCESS) {
                $this->error("Command {$commandName} exited with code {$exitCode}.");

                return $exitCode;
            }
        }

        $this->info('Core setup commands completed.');

        return Command::SUCCESS;
    }
}
