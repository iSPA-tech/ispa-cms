<?php

use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Plain resource with create and update forms. */
class CustomRulesPlainResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'customrules/things';
    }

    /** Ability. */
    public function ability(): string
    {
        return 'customrules.things.view';
    }

    /** Fields. */
    public function fields(): array
    {
        return [Field::text('name')->required()->max(50)];
    }

    /** Form with both contexts. */
    public function form(): ?FormView
    {
        return FormView::create(['name'])->endpoint('x.store')->update(['name'])->updateEndpoint('x.update');
    }

    /** One rule set. */
    public function ruleSets(): array
    {
        return ['bulk' => [$this->field('name')]];
    }
}

/** Opted-in variant. */
class CustomRulesOptedResource extends CustomRulesPlainResource
{
    /** Opt in. */
    public function supportsCustomFields(): bool
    {
        return true;
    }
}

beforeEach(function () {
    app(ResourceRegistry::class)->register('customrules/things', CustomRulesOptedResource::class);
    $service = app(CustomFieldService::class);
    $l = ['vi' => 'Nhan', 'en' => 'Label'];
    $opts = [['value' => 'a', 'labels' => $l], ['value' => 'b', 'labels' => $l], ['value' => 'c', 'labels' => $l]];
    $service->create('customrules/things', ['key' => 'industry', 'type' => 'text', 'labels' => $l, 'is_required' => true,
        'rules' => ['max' => 5, 'pattern' => '^[a-z]+$']], null);
    $service->create('customrules/things', ['key' => 'age', 'type' => 'integer', 'labels' => $l, 'rules' => ['min' => 1, 'max' => 10]], null);
    $service->create('customrules/things', ['key' => 'tier', 'type' => 'select', 'labels' => $l, 'options' => $opts], null);
    $service->create('customrules/things', ['key' => 'tags', 'type' => 'multiselect', 'labels' => $l, 'options' => $opts, 'rules' => ['max' => 2]], null);
});

/** Validate input against the resource rules; returns errors. */
function crErrors(string $context, array $input, ?Resource $r = null): array
{
    $r ??= new CustomRulesOptedResource;

    return Validator::make($input, $r->rules($context, $input))->errors()->toArray();
}

it('rejects a missing required custom value on create', function () {
    $errors = crErrors('create', ['name' => 'x']);
    expect(array_keys($errors))->toBe(['custom_fields.industry']);
    expect(crErrors('create', ['name' => 'x', 'custom_fields' => ['industry' => 'abc']]))->toBe([]);
});

it('accepts a missing key on update but rejects an explicit empty required value', function () {
    expect(crErrors('update', ['name' => 'x']))->toBe([]);
    expect(crErrors('update', ['name' => 'x', 'custom_fields' => ['age' => 3]]))->toBe([]);
    expect(array_keys(crErrors('update', ['name' => 'x', 'custom_fields' => ['industry' => '']])))->toContain('custom_fields.industry');
});

it('enforces text max and pattern', function () {
    $base = ['name' => 'x'];
    expect(array_keys(crErrors('create', $base + ['custom_fields' => ['industry' => 'abcdefg']])))->toBe(['custom_fields.industry']);
    expect(array_keys(crErrors('create', $base + ['custom_fields' => ['industry' => 'AB1']])))->toBe(['custom_fields.industry']);
});

it('enforces integer range, select membership and multiselect max count', function () {
    $ok = ['industry' => 'abc'];
    $e = fn (array $extra) => array_keys(crErrors('create', ['name' => 'x', 'custom_fields' => $ok + $extra]));
    expect($e(['age' => 11]))->toBe(['custom_fields.age']);
    expect($e(['age' => 0]))->toBe(['custom_fields.age']);
    expect($e(['age' => 5]))->toBe([]);
    expect($e(['tier' => 'z']))->toBe(['custom_fields.tier']);
    expect($e(['tier' => 'a']))->toBe([]);
    expect($e(['tags' => ['a', 'b', 'c']]))->toBe(['custom_fields.tags']);
    expect($e(['tags' => ['a', 'b']]))->toBe([]);
});

it('rejects unknown and archived keys with dot error keys', function () {
    $errors = crErrors('create', ['name' => 'x', 'custom_fields' => ['industry' => 'abc', 'ghost' => 1]]);
    expect(array_keys($errors))->toBe(['custom_fields.ghost']);

    $service = app(CustomFieldService::class);
    $attr = $service->fieldsFor('customrules/things')->firstWhere('name', 'cf.customrules.things.age');
    $service->archive($attr);
    $errors = crErrors('create', ['name' => 'x', 'custom_fields' => ['industry' => 'abc', 'age' => 3]]);
    expect(array_keys($errors))->toBe(['custom_fields.age']);
});

it('leaves action, rule set and non opted contexts unchanged', function () {
    $opted = new CustomRulesOptedResource;
    $plain = new CustomRulesPlainResource;
    expect($opted->rules('bulk', ['custom_fields' => ['x' => 1]]))->toBe($plain->rules('bulk'));

    // The plain resource shares the key (attribute rows exist) but did not opt in.
    expect($plain->rules('create'))->toHaveKey('name')->and(array_keys($plain->rules('create')))->toBe(['name']);
    expect(array_keys($plain->rules('update', ['custom_fields' => ['x' => 1]])))->toBe(['name']);
    expect(fn () => $opted->rules('nope'))->toThrow(InvalidArgumentException::class);
});
