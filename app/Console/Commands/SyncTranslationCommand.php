<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Cms\Core\Models\Translate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Database\Seeders\TranslateTableSeed;

class SyncTranslationCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:sync-translation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all current translate keys and sync them again';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Syncing translation...');

        Schema::disableForeignKeyConstraints();

        try {
            // Truncate the widget and related table
            (new Translate)->truncate();

            // Sync permissions again by db seed class
            $this->call('db:seed', [
                '--class' => TranslateTableSeed::class,
                '--force' => true,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        //  Call clear cache
        $this->call('cache:clear');

        $this->info('Translation synced successfully.');
    }
}
