<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use App\Constants\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs email templates from a seeder export file into the database.
 *
 * Loads templates from database/seeders/exported/email_templates.php (falling back to the
 * Core module export path). Each template is inserted only if no matching record already
 * exists for the same language_id, group, and key_name combination. Foreign key constraints
 * are disabled during the sync. The application cache is cleared after the import completes.
 *
 * Usage: php artisan cms:sync-email-template
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SyncEmailTemplateCommand extends Command
{
    protected $signature = 'cms:sync-email-template {--skip-cache-clear : Preserve the application cache after synchronization}';

    protected $description = 'Remove all email templates and sync them again';

    /**
     * Execute the console command.
     *
     * Locates the email template export file, inserts missing templates, and clears the cache.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->info('Syncing email templates...');

        Schema::disableForeignKeyConstraints();

        try {
            $path = database_path('seeders/exported/email_templates.php');
            $modulePath = base_path('cms/Core/database/seeders/exported/email_templates.php');

            if (!File::exists($path)) {
                $this->warn('Email template export not found at database/seeders/exported; trying Core module export.');
                $path = $modulePath;
            }

            if (!File::exists($path)) {
                $this->error('Email template export file not found at expected locations.');
                return;
            }

            /** @var array<int, array|object> $emailTemplates */
            $emailTemplates = include $path;
            foreach ($emailTemplates as $template) {
                $existingTemplate = DB::table(Tables::EMAIL_TEMPLATES)
                    ->where('language_id', $template->language_id)
                    ->where('group', $template->group)
                    ->where('key_name', $template->key_name)
                    ->first();

                if ($existingTemplate) {
                    $this->info("Template already exists: " . $template->key_name);
                    continue;
                }

                DB::table(Tables::EMAIL_TEMPLATES)->insert([
                    'name' => $template->name,
                    'group' => $template->group,
                    'blade_layout' => $template->blade_layout,
                    'key_name' => $template->key_name,
                    'content' => $template->content,
                    'variables' => $template->variables,
                    'sample_data' => $template->sample_data,
                    'description' => $template->description,
                    'language_id' => $template->language_id,
                    'user_id' => $template->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->info("Inserted template: " . $template->key_name);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        if (! $this->option('skip-cache-clear')) {
            $this->call('cache:clear');
        }

        $this->info('Templates synced successfully.');
    }
}
