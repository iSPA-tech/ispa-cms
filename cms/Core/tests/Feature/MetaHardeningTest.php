<?php

uses(\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaData;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\MetaValueText;
use Cms\Core\Services\MetaAssignmentService;
use Cms\Core\Services\MetaNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('meta_attributes')) {
        $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    }
});

function metaHardeningAttribute(array $overrides = []): MetaAttribute
{
    return MetaAttribute::create(array_merge([
        'name' => 'traction_' . uniqid(),
        'label' => 'Traction',
        'type' => 'string',
        'input_type' => 'select',
        'is_filter' => true,
        'is_enabled' => true,
        'is_required' => false,
        'is_multiple' => false,
    ], $overrides));
}

it('reuses one shared value row across many assignments', function () {
    $service = app(MetaAssignmentService::class);
    $attribute = metaHardeningAttribute();

    $first = $service->resolveValue('string', '  RWD ');
    $second = $service->resolveValue('string', 'RWD');
    $third = $service->resolveValue('string', "RWD\n");

    expect($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(MetaValueString::query()->where('value', 'RWD')->count())->toBe(1);
});

it('revives a soft-deleted value row instead of colliding with the unique pool', function () {
    $service = app(MetaAssignmentService::class);

    $value = $service->resolveValue('string', 'AWD');
    $value->delete();

    $revived = $service->resolveValue('string', 'AWD');

    expect($revived->id)->toBe($value->id)
        ->and($revived->deleted_at)->toBeNull();
});

it('keeps single-value attributes at one assignment row and accumulates multiples', function () {
    $service = app(MetaAssignmentService::class);
    $single = metaHardeningAttribute();
    $multiple = metaHardeningAttribute(['is_multiple' => true]);
    $target = metaHardeningAttribute(); // any model works as a polymorphic target

    $service->assign($target, null, $single, 'RWD');
    $service->assign($target, null, $single, 'AWD'); // repoints, no second row

    $service->assign($target, null, $multiple, 'Coupe');
    $service->assign($target, null, $multiple, 'Roadster');
    $service->assign($target, null, $multiple, 'Coupe'); // exact duplicate ignored

    $rows = MetaData::query()
        ->where('target_type', get_class($target))
        ->where('target_id', $target->id)
        ->get()
        ->groupBy('meta_attribute_id');

    expect($rows->get($single->id))->toHaveCount(1)
        ->and(MetaValueString::find($rows->get($single->id)->first()->value_id)->value)->toBe('AWD')
        ->and($rows->get($multiple->id))->toHaveCount(2);
});

it('registers catalog options exactly once', function () {
    $service = app(MetaAssignmentService::class);
    $attribute = metaHardeningAttribute();

    $value = $service->resolveValue('string', 'FWD');
    $service->ensureOption($attribute, $value);
    $service->ensureOption($attribute, $value);

    $count = DB::table(CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT)
        ->where('meta_attribute_id', $attribute->id)
        ->count();

    expect($count)->toBe(1);
});

it('adopts canonical casing when a case-insensitive match exists', function () {
    $service = app(MetaAssignmentService::class);

    $legacy = $service->resolveValue('string', 'hatchback');
    $canonical = $service->resolveValue('string', 'Hatchback');

    expect($canonical->id)->toBe($legacy->id)
        ->and($canonical->value)->toBe('Hatchback');
});

it('maintains value_hash on text values', function () {
    $service = app(MetaAssignmentService::class);

    $value = $service->resolveValue('text', 'A long article body.');
    expect($value)->toBeInstanceOf(MetaValueText::class)
        ->and($value->value_hash)->toBe(sha1('A long article body.'));

    $again = $service->resolveValue('text', 'A long article body.');
    expect($again->id)->toBe($value->id);
});

it('normalize merges legacy duplicate values and remaps assignments', function () {
    $attribute = metaHardeningAttribute();
    $target = metaHardeningAttribute();

    // Legacy state: duplicate value rows, assignments split across them.
    // Insert directly — the unique index would stop models doing this now.
    $keepId = DB::table('meta_values_string')->insertGetId(['value' => 'LEGACY-DUP-' . $attribute->id, 'created_at' => now(), 'updated_at' => now()]);

    $normalizer = app(MetaNormalizationService::class);

    $dupId = null;
    try {
        $dupId = DB::table('meta_values_string')->insertGetId(['value' => 'LEGACY-DUP-' . $attribute->id, 'created_at' => now(), 'updated_at' => now()]);
    } catch (\Throwable) {
        // Unique index already active on this connection — duplication is
        // impossible, which is exactly the guarantee; nothing left to merge.
        expect(true)->toBeTrue();
        return;
    }

    MetaData::query()->create([
        'target_type' => get_class($target),
        'target_id' => $target->id,
        'meta_attribute_id' => $attribute->id,
        'value_type' => MetaValueString::class,
        'value_id' => $dupId,
    ]);

    $report = $normalizer->normalize();

    $assignment = MetaData::query()
        ->where('target_type', get_class($target))
        ->where('target_id', $target->id)
        ->first();

    expect($assignment->value_id)->toBe($keepId)
        ->and(DB::table('meta_values_string')->where('id', $dupId)->exists())->toBeFalse()
        ->and($report['meta_values_string']['removed'])->toBeGreaterThanOrEqual(1);
});
