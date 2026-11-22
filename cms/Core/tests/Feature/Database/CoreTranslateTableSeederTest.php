<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Database\Seeders\CoreTranslateTableSeeder;
use Cms\Core\Database\Seeders\EnumerateTableSeed;
use Cms\Core\Database\Seeders\EnumerateTypeTableSeed;
use Cms\Core\Database\Seeders\LanguageTableSeed;
use Cms\Core\Database\Seeders\MetaDataTableSeed;
use Cms\Core\Database\Seeders\PermissionTableSeed;
use Cms\Core\Database\Seeders\ProvinceTableSeed;
use Cms\Core\Database\Seeders\RoleTableSeed;
use Cms\Core\Database\Seeders\SettingTableSeed;
use Cms\Core\Database\Seeders\SignInAsPermissionSeeder;
use Cms\Core\Database\Seeders\FormAndFieldSeeder;
use Cms\Core\Database\Seeders\ThanglequocProvinceSeeder;
use Cms\Core\Database\Seeders\TranslateTableSeed;
use Cms\Core\Database\Seeders\UsersTableSeeder;
use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Console\Command;

test('core translate table seeder reports importer summary', function () {
    $mock = Mockery::mock(TranslationModuleSeederImporter::class);
    $mock->shouldReceive('importFromFile')
        ->once()
        ->with('Core', base_path('cms/Core/database/seeders/sql/translate.php'), base_path('cms/Core/database/seeders/sql'))
        ->andReturn([
            'found' => true,
            'valid' => true,
            'created' => 1,
            'linked' => 2,
            'skipped' => 0,
            'total' => 3,
            'path' => '',
        ]);

    app()->instance(TranslationModuleSeederImporter::class, $mock);

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('info')
        ->once()
        ->with('Core translations - added 1, linked 2, updated 0, skipped 0.');

    $seeder = Mockery::mock(CoreTranslateTableSeeder::class)->makePartial();
    $seeder->setCommand($command);
    $seeder->shouldReceive('call')->once()->with(Mockery::type('array'));

    $seeder->run();
});

test('core translate table seeder warns when import source missing', function () {
    $mock = Mockery::mock(TranslationModuleSeederImporter::class);
    $mock->shouldReceive('importFromFile')
        ->once()
        ->andReturn([
            'found' => false,
            'valid' => false,
            'created' => 0,
            'linked' => 0,
            'skipped' => 0,
            'total' => 0,
            'path' => '',
        ]);

    app()->instance(TranslationModuleSeederImporter::class, $mock);

    $command = Mockery::mock(Command::class);
    $command->shouldReceive('warn')
        ->once()
        ->with('Core: translations file not found.');

    $seeder = Mockery::mock(CoreTranslateTableSeeder::class)->makePartial();
    $seeder->setCommand($command);
    $seeder->shouldReceive('call')->once()->with(Mockery::type('array'));

    $seeder->run();
});

test('core translate table seeder seeds enumerations before users', function () {
    $mock = Mockery::mock(TranslationModuleSeederImporter::class);
    $mock->shouldReceive('importFromFile')
        ->once()
        ->andReturn([
            'found' => true,
            'valid' => true,
            'created' => 0,
            'linked' => 0,
            'skipped' => 0,
            'total' => 0,
            'path' => '',
        ]);

    app()->instance(TranslationModuleSeederImporter::class, $mock);

    $seeder = Mockery::mock(CoreTranslateTableSeeder::class)->makePartial();
    $seeder->shouldReceive('call')
        ->once()
        ->with([
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

    $seeder->run();
});
