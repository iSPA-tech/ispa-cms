<?php

namespace App\Console\Commands;

use DB;
use App\Constants\Tables;
use Illuminate\Console\Command;

class BackupEmailTemplateCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:backup-email-template';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Get all data from email_templates table, then generate php file to be imported to migration seeder
        $emailTemplates = DB::table(Tables::EMAIL_TEMPLATES)->get();

        $filePath = database_path('seeders/exported/email_templates.php');

        $fileContent = "<?php\n\nreturn " . var_export($emailTemplates->toArray(), true) . ";\n";

        file_put_contents($filePath, $fileContent);

        $this->info('Email templates have been backed up to ' . $filePath);
    }
}
