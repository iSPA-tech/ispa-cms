<?php

use Cms\Core\Database\Seeders\LanguageTableSeed;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    Event::fake();

    Schema::create('languages', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->string('path')->unique();
        $table->string('path_alias')->nullable();
        $table->string('code')->nullable();
        $table->string('flag')->nullable();
        $table->string('time_zone')->nullable();
        $table->boolean('default_web')->default(false);
        $table->boolean('default_adm')->default(false);
        $table->boolean('default_app')->default(false);
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->string('status')->nullable();
        $table->boolean('is_locked')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('languages');
});

test('language seed reuses a soft deleted language instead of inserting its id again', function () {
    DB::table('languages')->insert([
        languageRow([
            'id' => 1,
            'name' => 'Vietnamese',
            'path' => 'vi',
            'deleted_at' => now(),
        ]),
        languageRow([
            'id' => 2,
            'name' => 'English',
            'path' => 'en',
        ]),
    ]);

    $deletedAt = DB::table('languages')->where('id', 1)->value('deleted_at');

    $this->seed(LanguageTableSeed::class);

    expect(DB::table('languages')->where('id', 1)->count())->toBe(1)
        ->and(DB::table('languages')->where('id', 1)->value('deleted_at'))->toBe($deletedAt);
});

test('language seed refreshes definition fields without changing operator flags', function () {
    DB::table('languages')->insert([
        languageRow([
            'id' => 1,
            'name' => 'Vietnamese',
            'path' => 'vi',
        ]),
        languageRow([
            'id' => 2,
            'name' => 'Old English',
            'currency' => 'USD',
            'path' => 'en',
            'path_alias' => 'old-en',
            'code' => 'OLD',
            'flag' => 'old.svg',
            'time_zone' => 'Asia/Ho_Chi_Minh',
            'for_web' => false,
            'for_adm' => false,
            'for_app' => true,
            'default_web' => false,
            'default_adm' => false,
            'default_app' => true,
            'status' => 'status_inactive',
            'is_locked' => true,
        ]),
    ]);

    $this->seed(LanguageTableSeed::class);

    $english = DB::table('languages')->where('id', 2)->first();

    expect(DB::table('languages')->where('id', 2)->count())->toBe(1)
        ->and($english->name)->toBe('English')
        ->and($english->currency)->toBe('$')
        ->and($english->path_alias)->toBe('en-US')
        ->and($english->code)->toBe('EN')
        ->and($english->flag)->toBe('en.svg')
        ->and($english->time_zone)->toBe('UTC')
        ->and((bool) $english->for_web)->toBeFalse()
        ->and((bool) $english->for_adm)->toBeFalse()
        ->and((bool) $english->for_app)->toBeTrue()
        ->and((bool) $english->default_web)->toBeFalse()
        ->and((bool) $english->default_adm)->toBeFalse()
        ->and((bool) $english->default_app)->toBeTrue()
        ->and($english->status)->toBe('status_inactive')
        ->and((bool) $english->is_locked)->toBeTrue();
});

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function languageRow(array $overrides = []): array
{
    return array_merge([
        'currency' => null,
        'path_alias' => null,
        'code' => null,
        'flag' => null,
        'time_zone' => null,
        'default_web' => false,
        'default_adm' => false,
        'default_app' => false,
        'for_web' => false,
        'for_adm' => false,
        'for_app' => false,
        'status' => null,
        'is_locked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ], $overrides);
}
