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

class MakeRepository extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:ispa {name} {--soft} {--api} {--simple} {--v=V2}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new Repository and Interface for Model';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Repository';

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
        return __DIR__.'/stubs/ModelRepository.stub';
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $eloquentFileName = app_path('Repositories/Eloquent/'.$this->argument('name').'Repository.php');

        if (! file_exists($eloquentFileName)) {
            $eloquentFileContent = str_replace('{{ name }}', $this->argument('name'), $this->files->get($this->getStub()));
            file_put_contents($eloquentFileName, $eloquentFileContent);
            $this->info($this->argument('name').' Repository files created');
        } else {
            $this->warn('Repository files already exists.');
        }

        $this->call('make:model-interface', ['name' => $this->argument('name')]);

        $this->call('make:ispa-model', [
            'name' => $this->argument('name'),
            '--soft' => $this->option('soft'),
        ]);

        if (! $this->option('simple')) {
            $this->call('make:model-observer', [
                'name' => $this->argument('name'),
            ]);

            $this->call('make:model-policy', [
                'name' => $this->argument('name'),
            ]);

            $this->call('make:model-controller', [
                'name' => $this->argument('name'),
                '--api' => $this->option('api'),
                '--v' => $this->option('v'),
            ]);

            $this->call('make:model-request', [
                'name' => $this->argument('name'),
            ]);
        }

        $this->info('Done');
    }
}
