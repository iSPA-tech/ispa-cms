<?php

namespace App\Console\Commands;

use JsonException;
use App\Constants\Tables;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncStaticTranslateKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:sync-static-translate-key';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import and merge static translation files into the database';

    /**
     * Indicates if the command is a discovery command for workflows.
     *
     * @var bool
     */
    public bool $isDiscovery = false;

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->info('Starting import and merge of static translations...');

        $locales = ['vi', 'en']; // Các ngôn ngữ hỗ trợ
        $translationsData = [];
        $now = Carbon::now();

        // Bước 1: Đọc tất cả các file ngôn ngữ và thu thập dữ liệu
        foreach ($locales as $locale) {
            $filePath = database_path("seeders/exported/setting-{$locale}.php");

            if (!file_exists($filePath)) {
                $this->error("File not found: {$filePath}");
                continue;
            }

            $translations = require $filePath;

            if (!is_array($translations)) {
                $this->error("Invalid translation format in file: {$filePath}");
                continue;
            }

            $this->collectTranslations($translationsData, $translations, $locale);
        }

        // Bước 2: Xử lý và merge dữ liệu đã thu thập
        $batch = [];
        $processedCount = 0;

        foreach ($translationsData as $group => $keys) {
            foreach ($keys as $key => $texts) {
                $fullKey = "setting.{$group}.{$key}"; // Thêm prefix 'setting' như yêu cầu

                $batch[] = [
                    'group_name' => 'setting', // Luôn là 'setting' như yêu cầu
                    'name' => $fullKey,
                    'text' => json_encode($texts, JSON_THROW_ON_ERROR),
                    'for_web' => 1,
                    'for_adm' => 1,
                    'for_app' => 0,
                    'status' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
            }
        }

        // Bước 3: Insert vào database theo batch
        foreach (array_chunk($batch, 100) as $chunk) {
            $processedCount += $this->insertTranslations($chunk);
        }

        $this->info("Completed! Imported/updated {$processedCount} translation records.");
        return 0;
    }

    /**
     * Thu thập dữ liệu từ các file ngôn ngữ vào một mảng thống nhất
     */
    protected function collectTranslations(array &$result, array $translations, string $locale): void
    {
        foreach ($translations as $group => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    // Xử lý nested array
                    foreach ($value as $subKey => $subValue) {
                        $this->addTranslation($result, $group, "{$key}.{$subKey}", $locale, $subValue);
                    }
                } else {
                    $this->addTranslation($result, $group, $key, $locale, $value);
                }
            }
        }
    }

    /**
     * Thêm bản dịch vào mảng kết quả
     */
    protected function addTranslation(array &$result, string $group, string $key, string $locale, string $value): void
    {
        if (!isset($result[$group][$key])) {
            $result[$group][$key] = [];
        }

        $result[$group][$key][$locale] = $value;
    }

    /**
     * Insert/update translations với cơ chế upsert
     */
    protected function insertTranslations(array $batch): int
    {
        foreach ($batch as $item) {
            $this->info("Insert/Update: " . $item['name']);
        }

        return DB::table(Tables::TRANSLATES)->upsert(
            $batch,
            ['group_name', 'name'], // Unique identifier
            [
                'text',
                'updated_at',
                'for_web',
                'for_adm',
                'for_app',
            ] // Fields to update if exists
        );
    }
}
