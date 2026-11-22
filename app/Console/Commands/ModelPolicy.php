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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModelPolicy extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:model-policy {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make Policy with Model';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Policy';

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
        return __DIR__.'/stubs/ModelPolicy.stub';
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $eloquentFileName = app_path('Policies/'.$this->argument('name').'Policy.php');

        if (! file_exists($eloquentFileName)) {
            $eloquentFileContent = str_replace(
          ['{{ name }}', '{{ name_lower }}'],
          [$this->argument('name'), Str::lower($this->argument('name'))],
          $this->files->get($this->getStub())
        );
            //  Make dir
            if (! File::exists(app_path('Policies/'))) {
                File::makeDirectory(app_path('Policies/'));
            }
            //  Put file content
            file_put_contents($eloquentFileName, $eloquentFileContent);
            $this->info($this->argument('name').' Policy files created!');
        } else {
            $this->warn('Policy files already exists.');
        }
    }
}
