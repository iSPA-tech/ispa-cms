<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Core\Database\Seeders\FormAndFieldSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('field_items');
    Schema::dropIfExists('fields');
    Schema::dropIfExists('enumerates');

    Schema::create('enumerates', static function (Blueprint $table): void {
        $table->string('enum_key')->primary();
    });
    Schema::create('fields', static function (Blueprint $table): void {
        $table->id();
        $table->string('form_id');
        $table->string('name');
        $table->json('custom')->nullable();
        $table->text('styles')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('expires_from')->nullable();
        $table->timestamp('expires_to')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->foreign('status')->references('enum_key')->on('enumerates');
    });
    Schema::create('field_items', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->foreignId('field_id')->constrained('fields')->cascadeOnDelete();
        $table->string('field_name');
        $table->string('type');
        $table->text('description')->nullable();
        $table->json('options')->nullable();
        $table->json('custom')->nullable();
        $table->integer('priority')->default(0);
        $table->string('name_trans_key')->nullable();
        $table->string('phd_trans_key')->nullable();
        $table->boolean('status')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    DB::table('enumerates')->insert(['enum_key' => StatusEnum::PUBLISHED->value]);
    Event::fake();
});

afterEach(function (): void {
    Schema::dropIfExists('field_items');
    Schema::dropIfExists('fields');
    Schema::dropIfExists('enumerates');
});

test('field export uses canonical published status accepted by the foreign key', function (): void {
    app(FormAndFieldSeeder::class)->run();

    expect(DB::table('fields')->count())->toBe(2)
        ->and(DB::table('fields')->distinct()->pluck('status')->all())
        ->toBe([StatusEnum::PUBLISHED->value])
        ->and(DB::table('field_items')->count())->toBe(10);
});
