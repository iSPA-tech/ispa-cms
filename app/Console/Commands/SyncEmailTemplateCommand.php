<?php

namespace App\Console\Commands;

use App\Constants\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SyncEmailTemplateCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:sync-email-template {--skip-cache-clear : Preserve the application cache after synchronization}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all email templates and sync them again';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Syncing permissions...');

        Schema::disableForeignKeyConstraints();

        try {
            //  Get data import from seeder file, then check if exists in db, if not then insert
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
                if (!$existingTemplate) {
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
                } else {
                    $this->info("Template already exists: " . $template->key_name);
                }
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
