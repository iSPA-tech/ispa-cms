<?php

use Cms\Core\Models\Language;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class)->in('Feature', 'Unit', 'Integration');

// Provide class aliases for legacy App\\Models\\MetaData namespace if referenced indirectly
$aliases = [
    'App\\Models\\MetaData\\MetaAttribute' => Cms\Core\Models\MetaAttribute::class,
    'App\\Models\\MetaData\\MetaClass' => Cms\Core\Models\MetaClass::class,
    'App\\Models\\MetaData\\MetaData' => Cms\Core\Models\MetaData::class,
    'App\\Models\\MetaData\\MetaValueBigNum' => Cms\Core\Models\MetaValueBigNum::class,
    'App\\Models\\MetaData\\MetaValueBoolean' => Cms\Core\Models\MetaValueBoolean::class,
    'App\\Models\\MetaData\\MetaValueDate' => Cms\Core\Models\MetaValueDate::class,
    'App\\Models\\MetaData\\MetaValueString' => Cms\Core\Models\MetaValueString::class,
    'App\\Models\\MetaData\\MetaValueText' => Cms\Core\Models\MetaValueText::class,
    'App\\Models\\MetaData\\MetaValueTinyNum' => Cms\Core\Models\MetaValueTinyNum::class,
];

foreach ($aliases as $legacy => $modern) {
    if (!class_exists($legacy) && class_exists($modern)) {
        class_alias($modern, $legacy);
    }
}

$sqlitePath = storage_path('framework/testing/testing.sqlite');
config()->set('database.connections.sqlite.database', $sqlitePath);

if (! file_exists($sqlitePath)) {
    touch($sqlitePath);
}

if (! cache()->has('language-locale-vi')) {
    $languageStub = new Language([
        'id' => 1,
        'name' => 'Vietnamese',
        'path' => 'vi',
        'path_alias' => 'vi',
        'flag' => null,
        'currency' => 'VND',
    ]);

    cache()->put('language-locale-vi', $languageStub, config('setting.cache.locale', 3600));
}

if (! Schema::connection('sqlite')->hasTable('languages')) {
    Schema::connection('sqlite')->create('languages', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('path')->unique();
        $table->string('path_alias')->nullable();
        $table->string('flag')->nullable();
        $table->string('currency')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

if (! DB::connection('sqlite')->table('languages')->where('path', 'vi')->exists()) {
    DB::connection('sqlite')->table('languages')->insert([
        'name' => 'Vietnamese',
        'path' => 'vi',
        'path_alias' => 'vi',
        'flag' => null,
        'currency' => 'VND',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
