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

class MakeRequest extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:model-request {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make custom Request';

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
        return __DIR__.'/stubs/ModelRequest.stub';
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $typeArray = ['Store', 'Update', 'MultiDelete'];
        foreach ($typeArray as $type) {
            $fileName = app_path('Http/Requests/'.$this->argument('name').'/'.$type.$this->argument('name').'Request.php');

            if (! file_exists($fileName)) {
                $fileContent = str_replace(
            ['{{ name }}', '{{ type }}'],
            [$this->argument('name'), $type],
            $this->files->get($this->getStub())
          );
                //  Make dir
                if (! File::exists(app_path('Http/Requests/'))) {
                    File::makeDirectory(app_path('Http/Requests/'));
                }
                if (! File::exists(app_path('Http/Requests/'.$this->argument('name').'/'))) {
                    File::makeDirectory(app_path('Http/Requests/'.$this->argument('name').'/'));
                }
                //  Put file content
                file_put_contents($fileName, $fileContent);
                $this->info($type.' Request file created!');
            } else {
                $this->warn($type.' Request files already exists.');
            }
        }
    }
}
