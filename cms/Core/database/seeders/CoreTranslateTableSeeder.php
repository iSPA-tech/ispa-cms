<?php

namespace Cms\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Cms\Core\Database\Seeders\Concerns\ReportsTranslationImport;

class CoreTranslateTableSeeder extends Seeder
{
    use ReportsTranslationImport;

    public function run(): void
    {
        $result = app(TranslationModuleSeederImporter::class)
            ->importFromFile('Core', __DIR__ . '/sql/translate.php', __DIR__ . '/sql');

        $this->reportTranslationImport('Core', $result);

        $this->call([
            EnumerateTypeTableSeed::class,
            EnumerateTableSeed::class,
            PermissionTableSeed::class,
            SignInAsPermissionSeeder::class,
            RoleTableSeed::class,
            UsersTableSeeder::class,
            LanguageTableSeed::class,
            TranslateTableSeed::class,
            ProvinceTableSeed::class,
            ThanglequocProvinceSeeder::class,
            SettingTableSeed::class,
            MetaDataTableSeed::class,
            FormAndFieldSeeder::class,
            WidgetSeeder::class,
        ]);
    }
}
