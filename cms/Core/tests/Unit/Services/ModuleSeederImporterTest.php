<?php

use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Services\ModuleSeederImporter;

uses(TestCase::class);

beforeEach(function () {
    Schema::dropAllTables();
    Schema::create('demo_seed', function ($table) {
        $table->increments('id');
        $table->string('name');
    });
});

afterEach(function () {
    Schema::dropIfExists('demo_seed');
});

it('inserts only missing ids and skips existing ones', function () {
    DB::table('demo_seed')->insert([
        ['id' => 1, 'name' => 'existing'],
    ]);

    $importer = new ModuleSeederImporter(chunkSize: 2);

    $result = $importer->import('demo_seed', [
        ['id' => 1, 'name' => 'dup'],
        ['id' => 2, 'name' => 'new'],
        ['id' => 3, 'name' => 'newer'],
        ['id' => null, 'name' => 'missing id'],
    ]);

    expect(DB::table('demo_seed')->count())->toBe(3);
    expect(DB::table('demo_seed')->where('id', 1)->value('name'))->toBe('existing');
    expect(DB::table('demo_seed')->where('id', 2)->value('name'))->toBe('new');
    expect(DB::table('demo_seed')->where('id', 3)->value('name'))->toBe('newer');

    expect($result['inserted'])->toBe(2);
    expect($result['skipped'])->toBe(2);
    expect($result['total'])->toBe(4);
});

