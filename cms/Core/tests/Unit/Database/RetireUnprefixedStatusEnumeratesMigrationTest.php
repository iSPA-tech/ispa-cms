<?php

use Cms\Core\Models\Enumerate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class);

function runRetireUnprefixedStatusMigration(): void
{
    (require dirname(__DIR__, 3) . '/database/migrations/2026_11_26_000100_retire_unprefixed_status_enumerates.php')->up();
}

function legacyEnumerateRow(int $id, string $name, string $key, string $type = 'status', int $typeId = 1): array
{
    return [
        'id' => $id, 'name' => $name, 'name_alias' => null, 'enum_key' => $key, 'type' => $type, 'type_id' => $typeId,
        'input_type' => 'radio', 'is_filter' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        'deleted_at' => null,
    ];
}

beforeEach(function (): void {
    Schema::dropIfExists('enumerates');
    Schema::dropIfExists('posts');
    Schema::create('enumerates', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('name_alias')->nullable();
        $table->string('enum_key')->unique();
        $table->string('type')->nullable();
        $table->unsignedBigInteger('type_id')->nullable();
        $table->string('input_type')->nullable();
        $table->boolean('is_filter')->default(false);
        $table->boolean('status')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('enumerates');
    Schema::dropIfExists('posts');
});

it('leaves each status once when the seeder added prefixed rows next to the old ones', function (): void {
    // The cms.ispa.io state: old rows 1-3 plus the prefixed rows the seeder upserted by enum_key.
    DB::table('enumerates')->insert([
        legacyEnumerateRow(1, 'Lưu nháp', 'draft'),
        legacyEnumerateRow(2, 'Chờ duyệt', 'pending'),
        legacyEnumerateRow(3, 'Công khai', 'published'),
        legacyEnumerateRow(4, 'Chưa xem xét', 'awaiting_review', 'organization_status', 3),
        legacyEnumerateRow(73, 'Lưu trữ', 'status_archived'),
        legacyEnumerateRow(117, 'Lưu nháp', 'status_draft'),
        legacyEnumerateRow(118, 'Chờ duyệt', 'status_pending'),
        legacyEnumerateRow(119, 'Công khai', 'status_published'),
        legacyEnumerateRow(120, 'Chưa xem xét', 'organization_status_awaiting_review', 'organization_status', 3),
    ]);
    DB::table('posts')->insert([['status' => 'published'], ['status' => 'status_published'], ['status' => 'draft']]);

    runRetireUnprefixedStatusMigration();
    runRetireUnprefixedStatusMigration(); // idempotent

    $statusKeys = Enumerate::query()->where('type', 'status')->orderBy('id')->pluck('enum_key')->all();
    expect($statusKeys)->toBe(['status_archived', 'status_draft', 'status_pending', 'status_published'])
        ->and(Enumerate::query()->where('type', 'organization_status')->pluck('enum_key')->all())
        ->toBe(['organization_status_awaiting_review'])
        ->and(Enumerate::withTrashed()->whereIn('id', [1, 2, 3, 4])->whereNotNull('deleted_at')->count())->toBe(4)
        ->and(DB::table('posts')->orderBy('id')->pluck('status')->all())
        ->toBe(['status_published', 'status_published', 'status_draft']);
});

it('creates the prefixed row from the old one when only the old row exists', function (): void {
    DB::table('enumerates')->insert([legacyEnumerateRow(3, 'Công khai', 'published')]);
    DB::table('posts')->insert(['status' => 'published']);

    runRetireUnprefixedStatusMigration();

    expect(Enumerate::query()->where('type', 'status')->pluck('name', 'enum_key')->all())
        ->toBe(['status_published' => 'Công khai'])
        ->and(DB::table('posts')->value('status'))->toBe('status_published');
});
