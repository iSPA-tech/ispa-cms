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
use Illuminate\Support\Str;

class MakeModel extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:ispa-model {name} {--soft}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make custom Model';

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
        return __DIR__.'/stubs/'.($this->option('soft') ? 'ModelModelSoft' : 'ModelModel').'.stub';
    }

    /**
     * Execute the console command.
     *
     * @return
     */
    public function handle()
    {
        $eloquentFileName = app_path('Models/'.$this->argument('name').'.php');

        if (! file_exists($eloquentFileName)) {
            $eloquentFileContent = str_replace(
          ['{{ name }}', '{{ name_lower }}'],
          [$this->argument('name'), Str::snake(Str::plural($this->argument('name')))],
          $this->files->get($this->getStub())
        );
            file_put_contents($eloquentFileName, $eloquentFileContent);
            $this->info($this->argument('name').' Model file created!.');
        } else {
            $this->warn('Model file already exists.');
        }
    }
}
