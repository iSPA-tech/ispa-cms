<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Database\Seeders\ProvinceTableSeed;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('provinces');
    Schema::dropIfExists('enumerates');
    Schema::dropIfExists(CoreTableConstant::SEED_FINGERPRINTS);

    Schema::create('enumerates', static function (Blueprint $table): void {
        $table->string('enum_key')->primary();
    });
    Schema::create('provinces', static function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('uid')->nullable();
        $table->string('name');
        $table->string('global_name')->nullable();
        $table->string('slug')->nullable();
        $table->unsignedTinyInteger('type')->default(0);
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->string('maps_id')->nullable();
        $table->decimal('longitude', 11, 8)->nullable();
        $table->decimal('latitude', 10, 8)->nullable();
        $table->integer('priority')->default(0);
        $table->json('config')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->foreign('status')->references('enum_key')->on('enumerates');
    });
    Schema::create(CoreTableConstant::SEED_FINGERPRINTS, static function (Blueprint $table): void {
        $table->id();
        $table->string('fingerprint_key', 160)->unique();
        $table->string('seeder_class');
        $table->char('source_hash', 64);
        $table->unsignedInteger('source_count')->default(0);
        $table->unsignedInteger('seeder_version')->default(1);
        $table->string('status', 20)->default('completed');
        $table->json('metadata')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });

    DB::table('enumerates')->insert(['enum_key' => StatusEnum::PUBLISHED->value]);
    Event::fake();
});

afterEach(function (): void {
    Schema::dropIfExists('provinces');
    Schema::dropIfExists('enumerates');
    Schema::dropIfExists(CoreTableConstant::SEED_FINGERPRINTS);
});

test('province export uses canonical published status accepted by the foreign key', function (): void {
    app(ProvinceTableSeed::class)->run();

    expect(DB::table('provinces')->count())->toBe(512)
        ->and(DB::table('provinces')->distinct()->pluck('status')->all())
        ->toBe([StatusEnum::PUBLISHED->value]);
});

test('province sync command inserts canonical published status', function (): void {
    expect(Artisan::call('cms:sync-province-data'))->toBe(Command::SUCCESS)
        ->and(DB::table('provinces')->count())->toBe(512)
        ->and(DB::table('provinces')->distinct()->pluck('status')->all())
        ->toBe([StatusEnum::PUBLISHED->value]);
});

test('province sync command repairs legacy status on existing exported rows', function (): void {
    $province = (require base_path('cms/Core/database/seeders/exported/provinces.php'))[0];
    Schema::disableForeignKeyConstraints();
    DB::table('provinces')->insert(array_merge($province, ['parent_id' => null]));
    Schema::enableForeignKeyConstraints();

    expect(Artisan::call('cms:sync-province-data'))->toBe(Command::SUCCESS)
        ->and(DB::table('provinces')->where('slug', $province['slug'])->value('status'))
        ->toBe(StatusEnum::PUBLISHED->value)
        ->and(Artisan::output())->toContain('updated 1');
});
