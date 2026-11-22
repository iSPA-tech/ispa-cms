<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use App\Models\Slug;
use Illuminate\Console\Command;

class SlugGenerate extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ispa:generate-slug {model} {column}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'generate slug by model and column';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Get the table name and column name
        $model = $this->argument('model'); //   Model namespace
        $column = $this->argument('column');
        //  Init the model
        $model = new $model;
        //  Get all records without slug relationship
        $records = $model->doesntHave('slugs')->get();

        foreach ($records as $record) {
            //  Generate slug
            $slug = str($record->{$column})->slug();
            $this->info('Slug: ' . $slug . ' for ' . $record->id);
            //  Create slug
            $slugs = Slug::query()->where([
                'target_type' => get_class($record),
                'target_id' => $record->id,
            ])->first();

            if (!$slugs) {
                $slugs = new Slug([
                    'target_type' => get_class($record),
                    'target_id' => $record->id,
                    'language_id' => $record->language_id,
                ]);
            }
            $slugs->slug = $slug;
            $slugs->language_id = $record->language_id;

            $slugs->save();
        }
    }
}
