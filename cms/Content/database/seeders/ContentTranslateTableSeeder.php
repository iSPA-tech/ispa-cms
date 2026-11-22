<?php

namespace Cms\Content\Database\Seeders;

use Cms\Core\Database\Seeders\Concerns\ReportsTranslationImport;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Database\Seeder;

class ContentTranslateTableSeeder extends Seeder
{
    use ReportsTranslationImport;

    public function run(): void
    {
        $result = app(TranslationModuleSeederImporter::class)
            ->importFromFile('Content', __DIR__ . '/sql/translate.php', __DIR__ . '/sql');

        $this->reportTranslationImport('Content', $result);
    }
}
