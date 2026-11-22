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
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class MakeController extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $files;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:model-controller {name} {--api} {--v=V2}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make controller with Model';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Controller';

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
        return __DIR__.'/stubs/'.($this->option('api') ? 'ModelControllerApi' : 'ModelController').'.stub';
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws FileNotFoundException
     */
    public function handle(): void
    {
        $apiDir = app_path('Http/Controllers'.($this->option('api') ? '/'.$this->option('v').'/' : '/'));

        $this->files->ensureDirectoryExists($apiDir);

        //  Generate Admin File
        $adminFileName = app_path('Http/Controllers/'.($this->option('api') ? $this->option('v').'/Admin/' : 'Admin').$this->argument('name').'Controller.php');

        if (! file_exists($adminFileName)) {
            $eloquentFileContent = str_replace(
                ['{{ name }}', '{{ api }}', '{{ type }}', '{{ lang }}', '{{ date }}', '{{ table }}'],
                [
                    $this->argument('name'), '\\'.$this->option('v').'\Admin', 'Admin',
                    Str::snake(Str::plural($this->argument('name'))), now()->format('Y-m-d H:i:s'),
                    Str::upper(Str::snake($this->argument('name')))
                ],
                $this->files->get($this->getStub())
            );
            $dir = app_path('Http/Controllers'.($this->option('api') ? '/'.$this->option('v').'/Admin/' : '/'));

            $this->files->ensureDirectoryExists($dir);

            file_put_contents($adminFileName, $eloquentFileContent);
            $this->info($this->argument('name').'Controller Admin file created!');
        } else {
            $this->warn('Admin Controller file already exists.');
        }

        //  Generate Client File
        $clientFileName = app_path('Http/Controllers/'.($this->option('api') ? $this->option('v').'/Client/' : 'Client').$this->argument('name').'Controller.php');

        if (! file_exists($clientFileName)) {
            $eloquentFileContent = str_replace(
                ['{{ name }}', '{{ api }}', '{{ type }}', '{{ lang }}'],
                [$this->argument('name'), '\\'.$this->option('v').'\Client', 'Client', Str::snake(Str::plural($this->argument('name')))],
                $this->files->get($this->getStub())
            );
            $dir = app_path('Http/Controllers'.($this->option('api') ? '/'.$this->option('v').'/Client/' : '/'));

            $this->files->ensureDirectoryExists($dir);

            file_put_contents($clientFileName, $eloquentFileContent);
            $this->info($this->argument('name').'Controller Client file created!');
        } else {
            $this->warn('Client Controller file already exists.');
        }
    }
}
