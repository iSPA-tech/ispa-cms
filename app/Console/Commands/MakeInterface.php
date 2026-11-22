<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeInterface extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:model-interface {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Interface';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * @inheritDoc
     */
    protected function getStub()
    {
        return __DIR__.'/stubs/ModelInterface.stub';
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $eloquentFileName = app_path('Repositories/Interfaces/'.$this->argument('name').'Interface.php');

        if (! file_exists($eloquentFileName)) {
            $eloquentFileContent = str_replace('{{ name }}', $this->argument('name'), $this->files->get($this->getStub()));
            file_put_contents($eloquentFileName, $eloquentFileContent);
            $this->info($this->argument('name').'Interface file created!');
        } else {
            $this->warn('Interface File already exists.');
        }
    }
}
