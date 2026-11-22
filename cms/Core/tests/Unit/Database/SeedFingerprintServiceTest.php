<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Seeders\SeedFingerprintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists(CoreTableConstant::SEED_FINGERPRINTS);
    Schema::create(CoreTableConstant::SEED_FINGERPRINTS, static function (Blueprint $table): void {
        $table->id();
        $table->string('fingerprint_key', 160)->unique();
        $table->string('seeder_class', 255);
        $table->char('source_hash', 64);
        $table->unsignedInteger('source_count')->default(0);
        $table->unsignedInteger('seeder_version')->default(1);
        $table->string('status', 20)->default('completed');
        $table->json('metadata')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(CoreTableConstant::SEED_FINGERPRINTS);
});

test('seed fingerprint service skips only matching completed sources with passing sanity check', function (): void {
    $service = app(SeedFingerprintService::class);
    $key = 'test_seed';
    $class = 'Tests\\Seeders\\TestSeeder';
    $hash = str_repeat('a', 64);

    expect($service->shouldSkip($key, $class, $hash, 10, 1, static fn (): bool => true))->toBeFalse();

    $service->markCompleted($key, $class, $hash, 10, 1, ['source' => 'fixture']);

    expect($service->shouldSkip($key, $class, $hash, 10, 1, static fn (): bool => true))->toBeTrue()
        ->and($service->shouldSkip($key, $class, str_repeat('b', 64), 10, 1, static fn (): bool => true))->toBeFalse()
        ->and($service->shouldSkip($key, $class, $hash, 10, 1, static fn (): bool => false))->toBeFalse()
        ->and($service->shouldSkip($key, $class, $hash, 11, 1, static fn (): bool => true))->toBeFalse()
        ->and($service->shouldSkip($key, $class, $hash, 10, 2, static fn (): bool => true))->toBeFalse();
});
