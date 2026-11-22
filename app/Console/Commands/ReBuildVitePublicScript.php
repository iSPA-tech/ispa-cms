<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ReBuildVitePublicScript extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:re-build';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pnpm or npm to build vite public script';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Rebuilding vite public script...');


        $command = (shell_exec('command -v pnpm')) ? ['pnpm', 'build'] : ['npm', 'run', 'build'];
        $process = new Process($command, base_path());

        $this->line('Running command: ' . implode(' ', $command) . ' at dir ' . base_path());

        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            $this->error('Failed to run the command.');
            return;
        }

        $this->info('Vite public script rebuilt successfully.');
    }
}
