<?php

declare(strict_types=1);

use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\ResourceOverlay;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\Sections\CustomFieldsSection;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Resource that opts in to custom fields. */
class CpfOrgResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cpf/orgs';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cpf.orgs.view';
    }

    /** No fields. */
    public function fields(): array
    {
        return [];
    }

    /** Opt in. */
    public function supportsCustomFields(): bool
    {
        return true;
    }
}

/** Resource that does not opt in. */
class CpfPlainResource extends CpfOrgResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cpf/plain';
    }

    /** Opt out. */
    public function supportsCustomFields(): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    $registry = app(ResourceRegistry::class);
    $registry->register('cpf/orgs', CpfOrgResource::class);
    $registry->register('cpf/plain', CpfPlainResource::class);
});

/** Two fields (one select) and an overlay on the source site. */
function cpfSeedSource(): void
{
    $service = app(CustomFieldService::class);
    $service->create('cpf/orgs', ['key' => 'industry', 'type' => 'text', 'labels' => ['vi' => 'Ngành', 'en' => 'Industry']], null);
    $service->create('cpf/orgs', [
        'key' => 'tier', 'type' => 'select', 'labels' => ['vi' => 'Hạng', 'en' => 'Tier'], 'is_required' => true,
        'options' => [
            ['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']],
            ['value' => 'silver', 'labels' => ['vi' => 'Bạc', 'en' => 'Silver']],
        ],
    ], null);
    ResourceOverlay::query()->create(['resource_key' => 'cpf/orgs', 'hidden' => ['phone'], 'order' => ['cf.cpf.orgs.tier'], 'labels' => []]);
}

/** Export the source site and wipe it, so the next step runs on an empty site. */
function cpfExportAndReset(): array
{
    $rows = app(CustomFieldsSection::class)->export(new ExportContext())->rows;
    DB::table(CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT)->delete();
    MetaAttribute::withTrashed()->forceDelete();
    DB::table('meta_classes')->delete();
    ResourceOverlay::query()->delete();

    return $rows;
}

it('exports definitions and the overlay but no record data', function (): void {
    cpfSeedSource();
    $rows = app(CustomFieldsSection::class)->export(new ExportContext())->rows;

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'kind'))->toBe(['field', 'field', 'overlay'])
        ->and(array_column(array_slice($rows, 0, 2), 'key'))->toBe(['industry', 'tier'])
        ->and($rows[1]['type'])->toBe('select')
        ->and($rows[1]['rules']['options'])->toHaveCount(2);

    $json = json_encode($rows);
    expect($json)->not->toContain('uuid')->and($json)->not->toContain('"id"');

    $package = app(ConfigPackageExporter::class)->build(['custom_fields'], []);
    expect($package['sections'])->toHaveKey('custom_fields')
        ->and((new PackageValidator())->validate($package)->errors ?? [])->toBe([])
        ->and(json_encode($package))->not->toContain('meta_data');
});

it('imports into an empty site with new uuids and reads the same definitions', function (): void {
    cpfSeedSource();
    $oldUuids = MetaAttribute::query()->pluck('uuid')->all();
    $rows = cpfExportAndReset();

    $result = app(CustomFieldsSection::class)->apply($rows, new ApplyContext());

    expect($result->updated)->toBe(3)->and($result->blocked)->toBe(0);
    $fields = app(CustomFieldService::class)->fieldsFor('cpf/orgs', true);
    expect($fields->pluck('name')->all())->toBe(['cf.cpf.orgs.industry', 'cf.cpf.orgs.tier'])
        ->and($fields->pluck('uuid')->all())->not->toEqual($oldUuids)
        ->and(array_intersect($fields->pluck('uuid')->all(), $oldUuids))->toBe([])
        ->and($fields[1]->is_required)->toBeTrue()
        ->and(ResourceOverlay::forResource('cpf/orgs')->hidden)->toBe(['phone']);

    // Re-export equals the original export (definitions are identical).
    expect(app(CustomFieldsSection::class)->export(new ExportContext())->rows)->toEqual($rows);
});

it('lists additions and changed rules in the dry run, and a second import changes nothing', function (): void {
    cpfSeedSource();
    $rows = cpfExportAndReset();
    $section = app(CustomFieldsSection::class);

    $diff = $section->diff($rows, new DiffContext());
    expect($diff->counts()['create'])->toBe(3);
    expect(MetaAttribute::query()->count())->toBe(0); // dry run writes nothing

    $section->apply($rows, new ApplyContext());
    expect($section->diff($rows, new DiffContext())->counts())->toMatchArray(['create' => 0, 'update' => 0, 'unchanged' => 3]);

    $again = $section->apply($rows, new ApplyContext());
    expect($again->updated)->toBe(0)->and($again->unchanged)->toBe(3)->and(MetaAttribute::query()->count())->toBe(2);

    $rows[0]['rules'] = ['max' => 50];
    $changed = $section->diff($rows, new DiffContext());
    expect($changed->item('cpf/orgs:industry')['action'])->toBe('update')
        ->and($changed->item('cpf/orgs:industry')['before']['rules'])->toBeNull()
        ->and($changed->item('cpf/orgs:industry')['after']['rules'])->toBe(['max' => 50]);
});

it('reports and skips a resource that is unknown or has not opted in', function (): void {
    $rows = [
        ['resource' => 'cpf/plain', 'kind' => 'field', 'key' => 'note', 'type' => 'text', 'labels' => ['vi' => 'Ghi chú', 'en' => 'Note'], 'position' => 1],
        ['resource' => 'cpf/missing', 'kind' => 'field', 'key' => 'note', 'type' => 'text', 'labels' => ['vi' => 'Ghi chú', 'en' => 'Note'], 'position' => 1],
        ['resource' => 'cpf/missing', 'kind' => 'overlay', 'hidden' => [], 'order' => [], 'labels' => []],
    ];

    $result = app(CustomFieldsSection::class)->apply($rows, new ApplyContext());

    expect($result->blocked)->toBe(3)->and($result->updated)->toBe(0)
        ->and(MetaAttribute::query()->count())->toBe(0)
        ->and(ResourceOverlay::query()->count())->toBe(0);
});

it('reports a type conflict and never overwrites the existing field or its values', function (): void {
    $service = app(CustomFieldService::class);
    $field = $service->create('cpf/orgs', ['key' => 'industry', 'type' => 'text', 'labels' => ['vi' => 'Ngành', 'en' => 'Industry']], null);
    $before = $field->fresh()->only(['type', 'input_type', 'labels', 'rules']);

    $rows = [['resource' => 'cpf/orgs', 'kind' => 'field', 'key' => 'industry', 'type' => 'integer', 'labels' => ['vi' => 'Ngành', 'en' => 'Industry'], 'position' => 1]];
    $result = app(CustomFieldsSection::class)->apply($rows, new ApplyContext());

    expect($result->blocked)->toBe(1)
        ->and($result->items[0]['reason'])->toContain('different type')
        ->and($field->fresh()->only(['type', 'input_type', 'labels', 'rules']))->toEqual($before);
});

it('keeps record values when an import updates a field and refuses dropping a used option', function (): void {
    cpfSeedSource();
    $rows = app(CustomFieldsSection::class)->export(new ExportContext())->rows;
    $tier = MetaAttribute::query()->where('name', 'cf.cpf.orgs.tier')->firstOrFail();
    $value = MetaValueString::query()->create(['value' => 'silver', 'label' => 'silver']);
    DB::table(CoreTableConstant::META_DATA)->insert([
        'meta_attribute_id' => $tier->id, 'target_type' => 'cpf', 'target_id' => 1,
        'value_type' => MetaValueString::class, 'value_id' => $value->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $dataBefore = DB::table(CoreTableConstant::META_DATA)->count();

    // Package drops the option that a record uses: blocked, nothing changes.
    $rows[1]['rules']['options'] = [['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']]];
    $result = app(CustomFieldsSection::class)->apply($rows, new ApplyContext());

    expect($result->blocked)->toBe(1)
        ->and(count($tier->fresh()->rules['options']))->toBe(2)
        ->and(DB::table(CoreTableConstant::META_DATA)->count())->toBe($dataBefore);
});

it('bumps the schema cache epochs after an apply', function (): void {
    cpfSeedSource();
    $rows = cpfExportAndReset();
    $section = app(CustomFieldsSection::class);
    Cache::forever('resource-schema-epoch:custom:cpf/orgs', 'old');
    Cache::forever('resource-schema-epoch:overlay:cpf/orgs', 'old');

    $result = $section->apply($rows, new ApplyContext());
    $section->afterApply(new ApplyContext());

    expect($result->updated)->toBeGreaterThan(0)
        ->and(Cache::get('resource-schema-epoch:custom:cpf/orgs'))->not->toBe('old')
        ->and(Cache::get('resource-schema-epoch:overlay:cpf/orgs'))->not->toBe('old');
});

it('skips rows the operator excludes and creates archived fields archived', function (): void {
    $rows = [
        ['resource' => 'cpf/orgs', 'kind' => 'field', 'key' => 'old_one', 'type' => 'text', 'labels' => ['vi' => 'Cũ', 'en' => 'Old'], 'archived' => true, 'position' => 1],
        ['resource' => 'cpf/orgs', 'kind' => 'field', 'key' => 'skipped', 'type' => 'text', 'labels' => ['vi' => 'Bỏ', 'en' => 'Skip'], 'position' => 2],
    ];

    $result = app(CustomFieldsSection::class)->apply($rows, new ApplyContext(['cpf/orgs:skipped' => false]));

    expect($result->updated)->toBe(1)->and($result->skipped)->toBe(1)
        ->and(MetaAttribute::query()->where('name', 'cf.cpf.orgs.old_one')->firstOrFail()->archived_at)->not->toBeNull()
        ->and(MetaAttribute::query()->where('name', 'cf.cpf.orgs.skipped')->exists())->toBeFalse();
});

it('is a known package section', function (): void {
    expect(PackageFormat::SECTIONS)->toContain('custom_fields')->and(PackageFormat::LIST_SECTIONS)->toContain('custom_fields');
});
