<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Database\Seeders\WidgetSeeder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SyncWidgetCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:sync-widgets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile widget, input, and template definitions by stable key';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Syncing widget...');
        // The only path that updates existing catalog rows: deploy seeding (db:seed, cms:modules-sync) is insert-only.
        $seeder = app(WidgetSeeder::class)->reconcileExisting();
        $seeder->setContainer(app())->setCommand($this);

        try {
            Model::unguarded(static fn () => $seeder->__invoke());
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Widget reconciliation failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Widgets synced successfully.');

        return self::SUCCESS;
    }
}
