<?php

declare(strict_types=1);

namespace Cms\Skeleton\Database\Seeders;

use Cms\Core\Database\Seeders\Concerns\ReportsTranslationImport;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Database\Seeder;

/** Imports the module's translation keys (sql/translate.php plus the translate-admin-*.php sidecars). */
final class SkeletonTranslateTableSeeder extends Seeder
{
    use ReportsTranslationImport;

    public function run(): void
    {
        $result = app(TranslationModuleSeederImporter::class)
            ->importFromFile('Skeleton', __DIR__ . '/sql/translate.php', __DIR__ . '/sql');
        $this->reportTranslationImport('Skeleton', $result);
    }
}
