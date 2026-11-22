<?php

use Cms\Core\Admin\CustomFields\CustomFieldException;
use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\MetaValueString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Test resource that opts in to custom fields. */
class CfOptedInResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cftest/organizations';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cftest.organizations.view';
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

/** Second opted-in resource. */
class CfSecondResource extends CfOptedInResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cftest/contacts';
    }
}

/** Resource that does not opt in. */
class CfPlainResource extends CfOptedInResource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cftest/plain';
    }

    /** Opt out. */
    public function supportsCustomFields(): bool
    {
        return false;
    }
}

beforeEach(function () {
    $registry = app(ResourceRegistry::class);
    $registry->register('cftest/organizations', CfOptedInResource::class);
    $registry->register('cftest/contacts', CfSecondResource::class);
    $registry->register('cftest/plain', CfPlainResource::class);
});

/**
 * Build the service, optionally overriding the value hooks.
 *
 * @param int|null    $valueCount Value count the hook reports, or null for the real one.
 * @param list<string> $inUse     Option values the hook reports as used.
 * @return CustomFieldService
 */
function cfService(?int $valueCount = null, array $inUse = []): CustomFieldService
{
    if ($valueCount === null && $inUse === []) {
        return new CustomFieldService(app(ResourceRegistry::class), new CustomFieldRules());
    }

    return new class(app(ResourceRegistry::class), new CustomFieldRules(), $valueCount, $inUse) extends CustomFieldService {
        public function __construct(ResourceRegistry $registry, CustomFieldRules $rules, private ?int $count, private array $used)
        {
            parent::__construct($registry, $rules);
        }

        protected function valueCount(MetaAttribute $field): int
        {
            return $this->count ?? parent::valueCount($field);
        }

        protected function optionInUse(MetaAttribute $field, string $value): bool
        {
            return in_array($value, $this->used, true);
        }
    };
}

/**
 * Valid field definition with overrides.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cfData(array $overrides = []): array
{
    return array_merge([
        'key' => 'industry',
        'type' => 'text',
        'labels' => ['vi' => 'Ngành', 'en' => 'Industry'],
    ], $overrides);
}

/**
 * Select field definition.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cfSelect(array $overrides = []): array
{
    return cfData(array_merge([
        'key' => 'tier',
        'type' => 'select',
        'options' => [
            ['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']],
            ['value' => 'silver', 'labels' => ['vi' => 'Bạc', 'en' => 'Silver']],
        ],
    ], $overrides));
}

/**
 * Row counts of every meta table that must stay untouched.
 *
 * @return array<string, int>
 */
function cfUntouchedCounts(): array
{
    $tables = [
        CoreTableConstant::META_DATA, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT,
        CoreTableConstant::META_VALUES_STRING, CoreTableConstant::META_VALUES_TEXT, CoreTableConstant::META_VALUES_DATE,
        CoreTableConstant::META_VALUES_BOOLEAN, CoreTableConstant::META_VALUES_TINY_NUM, CoreTableConstant::META_VALUES_BIG_NUM,
    ];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

/**
 * Assert a callback throws a CustomFieldException with a reason.
 *
 * @param callable $callback
 * @param string   $reason
 * @return void
 */
function expectCfReason(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe($reason);

        return;
    }
    test()->fail("Expected CustomFieldException [{$reason}] was not thrown.");
}

it('creates the class, the attribute and the pivot row and nothing else', function () {
    $before = cfUntouchedCounts();
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Field Admin', 'username' => 'field-admin', 'email' => 'field-admin@example.com',
        'email_verified_at' => now(), 'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);

    $field = cfService()->create('cftest/organizations', cfData([
        'help' => ['vi' => 'Gợi ý', 'en' => 'Hint'],
        'is_required' => true,
        'show_in_list' => true,
        'rules' => ['max' => 80],
    ]), $user->id);

    $class = MetaClass::where('resource_key', 'cftest/organizations')->firstOrFail();
    expect($class->type)->toBe('class')
        ->and($class->target)->toBe('cftest/organizations')
        ->and($class->user_id)->toBe($user->id)
        ->and($field->name)->toBe('cf.cftest.organizations.industry')
        ->and($field->is_custom)->toBeTrue()
        ->and($field->is_enabled)->toBeTrue()
        ->and($field->label)->toBe('Industry')
        ->and($field->labels)->toBe(['vi' => 'Ngành', 'en' => 'Industry'])
        ->and($field->help)->toBe(['vi' => 'Gợi ý', 'en' => 'Hint'])
        ->and($field->rules)->toBe(['max' => 80])
        ->and($field->type)->toBe('string')
        ->and($field->input_type)->toBe('text')
        ->and($field->is_multiple)->toBeFalse()
        ->and($field->is_required)->toBeTrue()
        ->and($field->show_in_list)->toBeTrue()
        ->and($field->is_searchable)->toBeFalse()
        ->and($field->priority)->toBe(1)
        ->and($field->uuid)->not->toBeEmpty()
        ->and(DB::table(CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT)->where('meta_class_id', $class->id)->where('meta_attribute_id', $field->id)->count())->toBe(1)
        ->and(MetaClass::count())->toBe(1)
        ->and(cfUntouchedCounts())->toBe($before);
});

it('reuses the class and gives the next priority', function () {
    $service = cfService();
    $first = $service->create('cftest/organizations', cfData(), null);
    $second = $service->create('cftest/organizations', cfData(['key' => 'region']), null);

    expect(MetaClass::count())->toBe(1)
        ->and($first->priority)->toBe(1)
        ->and($second->priority)->toBe(2);
});

it('maps select and multiselect and stores options without value rows', function () {
    $before = cfUntouchedCounts();
    $select = cfService()->create('cftest/organizations', cfSelect(), null);
    $multi = cfService()->create('cftest/organizations', cfSelect(['key' => 'tags', 'type' => 'multiselect', 'rules' => ['max' => 2]]), null);

    expect($select->input_type)->toBe('select')
        ->and($select->is_multiple)->toBeFalse()
        ->and($select->rules['options'][0])->toBe(['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']])
        ->and($multi->is_multiple)->toBeTrue()
        ->and($multi->rules['max'])->toBe(2)
        ->and(cfUntouchedCounts())->toBe($before);
});

it('gives the same key in another resource a different name', function () {
    $a = cfService()->create('cftest/organizations', cfData(), null);
    $b = cfService()->create('cftest/contacts', cfData(), null);

    expect($a->name)->toBe('cf.cftest.organizations.industry')
        ->and($b->name)->toBe('cf.cftest.contacts.industry')
        ->and(MetaClass::count())->toBe(2);
});

it('rejects unregistered and non opted in resources', function () {
    expectCfReason(fn () => cfService()->create('nope/nothing', cfData(), null), CustomFieldException::RESOURCE_NOT_SUPPORTED);
    expectCfReason(fn () => cfService()->create('cftest/plain', cfData(), null), CustomFieldException::RESOURCE_NOT_SUPPORTED);
    expectCfReason(fn () => cfService()->reorder('cftest/plain', []), CustomFieldException::RESOURCE_NOT_SUPPORTED);
    expect(MetaClass::count())->toBe(0)->and(MetaAttribute::custom()->count())->toBe(0);
});

it('rejects a duplicate key in the same resource', function () {
    cfService()->create('cftest/organizations', cfData(), null);

    expectCfReason(fn () => cfService()->create('cftest/organizations', cfData(), null), CustomFieldException::DUPLICATE_KEY);
});

it('rejects the 31st field, archived ones included', function () {
    $service = cfService();
    foreach (range(1, 30) as $i) {
        $field = $service->create('cftest/organizations', cfData(['key' => 'field_'.$i]), null);
    }
    $field->update(['archived_at' => now()]);

    expectCfReason(fn () => $service->create('cftest/organizations', cfData(['key' => 'one_more']), null), CustomFieldException::TOO_MANY_FIELDS);
    expect(fn () => $service->create('cftest/contacts', cfData(), null))->not->toThrow(CustomFieldException::class);
});

it('rejects bad keys, labels, help, types, rules and options', function (array $override) {
    try {
        cfService()->create('cftest/organizations', $override, null);
        test()->fail('Expected an invalid definition.');
    } catch (CustomFieldException $e) {
        expect($e->reason())->toBe(CustomFieldException::INVALID_DEFINITION)->and($e->errors())->not->toBeEmpty();
    }
    expect(MetaAttribute::custom()->count())->toBe(0);
})->with([
    'short key' => [fn () => cfData(['key' => 'a'])],
    'uppercase key' => [fn () => cfData(['key' => 'Industry'])],
    'digit first' => [fn () => cfData(['key' => '1abc'])],
    'long key' => [fn () => cfData(['key' => 'a'.str_repeat('b', 40)])],
    'missing key' => [fn () => cfData(['key' => null])],
    'missing english label' => [fn () => cfData(['labels' => ['vi' => 'Ngành']])],
    'no labels' => [fn () => cfData(['labels' => null])],
    'half help' => [fn () => cfData(['help' => ['vi' => 'Gợi ý']])],
    'bad type' => [fn () => cfData(['type' => 'color'])],
    'unknown rule' => [fn () => cfData(['rules' => ['bogus' => 1]])],
    'min above max' => [fn () => cfData(['rules' => ['min' => 9, 'max' => 3]])],
    'select without options' => [fn () => cfSelect(['options' => []])],
    'duplicate option' => [fn () => cfSelect(['options' => [
        ['value' => 'a', 'labels' => ['vi' => 'A', 'en' => 'A']], ['value' => 'a', 'labels' => ['vi' => 'B', 'en' => 'B']],
    ]])],
    'empty option value' => [fn () => cfSelect(['options' => [['value' => ' ', 'labels' => ['vi' => 'A', 'en' => 'A']]]])],
    'option missing label' => [fn () => cfSelect(['options' => [['value' => 'a', 'labels' => ['vi' => 'A']]]])],
    'options on text' => [fn () => cfData(['options' => [['value' => 'a', 'labels' => ['vi' => 'A', 'en' => 'A']]]])],
]);

it('lists fields by priority and hides archived ones unless asked', function () {
    $service = cfService();
    $a = $service->create('cftest/organizations', cfData(['key' => 'aaa']), null);
    $b = $service->create('cftest/organizations', cfData(['key' => 'bbb']), null);
    $c = $service->create('cftest/organizations', cfData(['key' => 'ccc']), null);
    $service->create('cftest/contacts', cfData(['key' => 'other']), null);
    $b->update(['archived_at' => now()]);

    expect($service->fieldsFor('cftest/organizations')->pluck('uuid')->all())->toBe([$a->uuid, $c->uuid])
        ->and($service->fieldsFor('cftest/organizations', true)->pluck('uuid')->all())->toBe([$a->uuid, $b->uuid, $c->uuid])
        ->and($service->fieldsFor('cftest/plain'))->toBeEmpty();
});

it('updates labels, help and flags but never the key', function () {
    $service = cfService();
    $field = $service->create('cftest/organizations', cfData(), null);

    $updated = $service->update($field, [
        'key' => 'industry',
        'labels' => ['vi' => 'Lĩnh vực', 'en' => 'Sector'],
        'help' => ['vi' => 'Chọn', 'en' => 'Pick'],
        'is_searchable' => true,
    ], 3);

    expect($updated->label)->toBe('Sector')
        ->and($updated->labels['vi'])->toBe('Lĩnh vực')
        ->and($updated->help['en'])->toBe('Pick')
        ->and($updated->is_searchable)->toBeTrue()
        ->and($updated->name)->toBe('cf.cftest.organizations.industry');

    expectCfReason(fn () => $service->update($field, ['key' => 'sector']), CustomFieldException::KEY_IMMUTABLE);
    expect($field->fresh()->name)->toBe('cf.cftest.organizations.industry');
});

it('refuses a type change when values exist and allows it otherwise', function () {
    $field = cfService()->create('cftest/organizations', cfData(), null);

    expectCfReason(fn () => cfService(2)->update($field, ['type' => 'integer']), CustomFieldException::TYPE_LOCKED);
    expect($field->fresh()->type)->toBe('string');

    $changed = cfService(0)->update($field, ['type' => 'integer']);
    expect($changed->type)->toBe('bigInt')->and($changed->input_type)->toBe('number')->and($changed->rules)->toBeNull();
});

it('allows a same-type update even when values exist', function () {
    $field = cfService()->create('cftest/organizations', cfData(), null);

    $updated = cfService(5)->update($field, ['type' => 'text', 'labels' => ['vi' => 'X', 'en' => 'Y']]);

    expect($updated->label)->toBe('Y');
});

it('counts real assignment rows by default', function () {
    $field = cfService()->create('cftest/organizations', cfData(), null);
    DB::table(CoreTableConstant::META_DATA)->insert([
        'meta_attribute_id' => $field->id, 'target_type' => 'x', 'target_id' => 1,
        'value_type' => MetaValueString::class, 'value_id' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expectCfReason(fn () => cfService()->update($field, ['type' => 'textarea']), CustomFieldException::TYPE_LOCKED);
});

it('adds and relabels options but keeps stored rules when options are omitted', function () {
    $service = cfService();
    $field = $service->create('cftest/organizations', cfSelect(), null);

    $options = [
        ['value' => 'gold', 'labels' => ['vi' => 'Vàng kim', 'en' => 'Gold plus']],
        ['value' => 'silver', 'labels' => ['vi' => 'Bạc', 'en' => 'Silver']],
        ['value' => 'bronze', 'labels' => ['vi' => 'Đồng', 'en' => 'Bronze']],
    ];
    $updated = cfService(0, ['gold', 'silver'])->update($field, ['options' => $options]);
    expect($updated->rules['options'])->toBe($options);

    $kept = $service->update($updated, ['is_required' => true]);
    expect($kept->rules['options'])->toBe($options);
});

it('refuses to remove an option records use and allows removing an unused one', function () {
    $field = cfService()->create('cftest/organizations', cfSelect(), null);
    $onlyGold = [['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']]];

    expectCfReason(fn () => cfService(0, ['silver'])->update($field, ['options' => $onlyGold]), CustomFieldException::OPTION_IN_USE);
    expect($field->fresh()->rules['options'])->toHaveCount(2);

    $updated = cfService(0, ['gold'])->update($field, ['options' => $onlyGold]);
    expect($updated->rules['options'])->toHaveCount(1);
});

it('detects option use from real assignments without writing anything', function () {
    $service = cfService();
    $field = $service->create('cftest/organizations', cfSelect(), null);
    $value = MetaValueString::query()->create(['value' => 'silver', 'label' => 'silver']);
    DB::table(CoreTableConstant::META_DATA)->insert([
        'meta_attribute_id' => $field->id, 'target_type' => 'x', 'target_id' => 1,
        'value_type' => MetaValueString::class, 'value_id' => $value->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = cfUntouchedCounts();
    $onlyGold = [['value' => 'gold', 'labels' => ['vi' => 'Vàng', 'en' => 'Gold']]];

    // The hook override reports 0 values so the real optionInUse query is what refuses the removal.
    $probe = new class(app(ResourceRegistry::class), new CustomFieldRules()) extends CustomFieldService {
        protected function valueCount(MetaAttribute $field): int
        {
            return 0;
        }
    };
    expectCfReason(fn () => $probe->update($field, ['options' => $onlyGold]), CustomFieldException::OPTION_IN_USE);
    expect(cfUntouchedCounts())->toBe($before);
});

it('reorders fields and rejects unknown, foreign and repeated uuids', function () {
    $service = cfService();
    $a = $service->create('cftest/organizations', cfData(['key' => 'aaa']), null);
    $b = $service->create('cftest/organizations', cfData(['key' => 'bbb']), null);
    $c = $service->create('cftest/organizations', cfData(['key' => 'ccc']), null);
    $foreign = $service->create('cftest/contacts', cfData(['key' => 'other']), null);

    $service->reorder('cftest/organizations', [$c->uuid, $a->uuid, $b->uuid]);
    expect($service->fieldsFor('cftest/organizations')->pluck('name')->all())->toBe([
        'cf.cftest.organizations.ccc', 'cf.cftest.organizations.aaa', 'cf.cftest.organizations.bbb',
    ])->and($c->fresh()->priority)->toBe(1)->and($b->fresh()->priority)->toBe(3);

    expectCfReason(fn () => $service->reorder('cftest/organizations', [$a->uuid, $foreign->uuid]), CustomFieldException::UNKNOWN_FIELD);
    expectCfReason(fn () => $service->reorder('cftest/organizations', [$a->uuid, 'missing']), CustomFieldException::UNKNOWN_FIELD);
    expectCfReason(fn () => $service->reorder('cftest/organizations', [$a->uuid, $a->uuid]), CustomFieldException::UNKNOWN_FIELD);
    expect($a->fresh()->priority)->toBe(2);
});

it('keeps unlisted fields after the listed ones when reordering', function () {
    $service = cfService();
    $a = $service->create('cftest/organizations', cfData(['key' => 'aaa']), null);
    $b = $service->create('cftest/organizations', cfData(['key' => 'bbb']), null);

    $service->reorder('cftest/organizations', [$b->uuid]);

    expect($b->fresh()->priority)->toBe(1)->and($a->fresh()->priority)->toBe(2);
});

it('stores and updates the can-filter flag', function () {
    $service = cfService();
    $field = $service->create('cftest/organizations', cfData(['is_filterable' => true]), null);
    expect($field->is_filter)->toBeTrue();

    $updated = $service->update($field, ['is_filterable' => false]);
    expect($updated->is_filter)->toBeFalse();
});

it('builds an unsaved draft with the same checks as create and writes nothing', function () {
    $service = cfService();
    $before = MetaAttribute::query()->count();

    $draft = $service->draft('cftest/organizations', cfData(['is_filterable' => true, 'is_required' => true]));
    expect($draft->exists)->toBeFalse()
        ->and($draft->name)->toBe('cf.cftest.organizations.industry')
        ->and($draft->is_filter)->toBeTrue()
        ->and($draft->is_required)->toBeTrue()
        ->and(MetaAttribute::query()->count())->toBe($before);

    expectCfReason(fn () => $service->draft('cftest/organizations', cfData(['labels' => ['vi' => '', 'en' => 'X']])), CustomFieldException::INVALID_DEFINITION);
    expectCfReason(fn () => $service->draft('cftest/organizations', cfSelect(['options' => []])), CustomFieldException::INVALID_DEFINITION);
    expectCfReason(fn () => $service->draft('cftest/plain', cfData()), CustomFieldException::RESOURCE_NOT_SUPPORTED);
});
