<?php

use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

uses(\Cms\Core\Tests\TestCase::class);

/** Resource with view/manage abilities, a row action and a bulk action. */
class BuilderAbilitiesResource extends Resource
{
    /** @param  array{view: string, manage: string}|null  $declared */
    public function __construct(private readonly ?array $declared) {}

    /** Resource key. */
    public function key(): string
    {
        return 'test/items';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'items.view';
    }

    /** Declared abilities. */
    public function abilities(): ?array
    {
        return $this->declared;
    }

    /** Fields. */
    public function fields(): array
    {
        return [Field::text('name')->required(), Field::slug('slug', 'name')];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()->columns(['name'])->endpoint('test.items.index')->rowActions(['toggle'])->bulkActions(['purge'])->selectable();
    }

    /** Form view. */
    public function form(): ?FormView
    {
        return FormView::create(['name', 'slug'])->endpoint('test.items.store');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()->endpoint('test.items.show')->sections(['name']);
    }

    /** Actions. */
    public function actions(): array
    {
        return [
            Action::make('toggle')->label('x.toggle')->labelFrom('active', 'x.deactivate', 'x.activate')->endpoint('test.items.toggle'),
            Action::make('purge')->label('x.purge')->bulk()->endpoint('test.items.purge'),
        ];
    }
}

/** Standalone validator (unit tests do not boot the framework's validation factory). */
function builderValidator(array $data, array $rules): Validator
{
    return new Validator(new Translator(new ArrayLoader, 'en'), $data, $rules);
}

/** Authenticatable with the given id. */
function builderUser(int $id): User
{
    $user = new User;
    $user->id = $id;

    return $user;
}

beforeEach(function () {
    $p = 'api/v2/admin/test/items';
    Route::get($p, fn () => null)->name('test.items.index');
    Route::post($p, fn () => null)->name('test.items.store');
    Route::get($p.'/{uuid}', fn () => null)->name('test.items.show');
    Route::post($p.'/{uuid}/toggle', fn () => null)->name('test.items.toggle');
    Route::post($p.'/purge', fn () => null)->name('test.items.purge');
    app('router')->getRoutes()->refreshNameLookups();

    Gate::define('items.view', fn ($user) => true);
    Gate::define('items.manage', fn ($user) => $user->id === 1);
});

it('exports multiple on a relation field and validates a list of uuids', function () {
    $field = Field::relation('tags', 'x/tags')->multiple();
    $schema = $field->toSchema();

    expect($schema['multiple'])->toBeTrue()
        ->and($schema['relation']['multiple'])->toBeTrue();

    $uuid = '5f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';
    $ok = builderValidator(['tags' => [$uuid]], ['tags' => $field->rulesFor('create')]);
    expect($ok->passes())->toBeTrue();
    $bad = builderValidator(['tags' => ['nope']], ['tags' => $field->rulesFor('create')]);
    expect($bad->fails())->toBeTrue();
});

it('runs the exists check on every item of a multiple relation', function () {
    $uuid = '5f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';
    $field = Field::relation('tags', 'x/tags')->multiple()->relationExists(fn (string $v) => $v === $uuid);
    $other = '6f1d6b0e-1c2a-4c63-9d57-0a3f4b9d7e11';

    expect(builderValidator(['tags' => [$uuid]], ['tags' => $field->rulesFor('create')])->passes())->toBeTrue()
        ->and(builderValidator(['tags' => [$uuid, $other]], ['tags' => $field->rulesFor('create')])->fails())->toBeTrue();
});

it('keeps the single relation schema free of multiple and depends_on', function () {
    $schema = Field::relation('o', 'x')->toSchema();

    expect($schema)->not->toHaveKey('multiple')
        ->and($schema['relation'])->not->toHaveKeys(['multiple', 'depends_on']);
});

it('exports depends_on on a relation field', function () {
    $schema = Field::relation('district', 'x/districts')->dependsOn('province', 'province_uuid')->toSchema();

    expect($schema['relation']['depends_on'])->toBe(['field' => 'province', 'param' => 'province_uuid']);
    expect(fn () => Field::text('a')->dependsOn('b', 'c'))->toThrow(LogicException::class);
});

it('creates a slug field following another field', function () {
    $field = Field::slug('slug', 'name');

    expect($field->toSchema())->toBe(['field' => 'slug', 'type' => 'slug', 'label' => 'slug', 'rules' => [], 'from' => 'name'])
        ->and($field->rulesFor('create'))->toBe(['nullable', 'string']);
});

it('exports label_from on an action and rejects empty parts', function () {
    $schema = (new SchemaBuilder)->build(new BuilderAbilitiesResource(null), builderUser(1));

    expect($schema['actions'][0]['label_from'])->toBe(['field' => 'active', 'on' => 'x.deactivate', 'off' => 'x.activate'])
        ->and($schema['actions'][0]['label'])->toBe('x.toggle')
        ->and($schema['actions'][1])->not->toHaveKey('label_from');
    expect(fn () => Action::make('a')->labelFrom('', 'x', 'y'))->toThrow(InvalidArgumentException::class);
});

it('exports no abilities and keeps everything without a declaration', function () {
    $schema = (new SchemaBuilder)->build(new BuilderAbilitiesResource(null), builderUser(2));

    expect($schema)->not->toHaveKey('abilities')
        ->and($schema['form'])->not->toBeNull()
        ->and($schema['actions'])->toHaveCount(2)
        ->and($schema['list'])->toHaveKeys(['rowActions', 'bulkActions', 'selectable']);
});

it('exports held abilities and keeps writes for a manager', function () {
    $schema = (new SchemaBuilder)->build(new BuilderAbilitiesResource(['view' => 'items.view', 'manage' => 'items.manage']), builderUser(1));

    expect($schema['abilities'])->toBe(['view' => true, 'manage' => true])
        ->and($schema['form'])->not->toBeNull()
        ->and($schema['actions'])->toHaveCount(2)
        ->and($schema['list']['rowActions'])->toBe(['toggle']);
});

it('strips form, actions and list actions for a view-only user', function () {
    $schema = (new SchemaBuilder)->build(new BuilderAbilitiesResource(['view' => 'items.view', 'manage' => 'items.manage']), builderUser(2));

    expect($schema['abilities'])->toBe(['view' => true, 'manage' => false])
        ->and($schema['form'])->toBeNull()
        ->and($schema['actions'])->toBe([])
        ->and($schema['list'])->not->toHaveKeys(['rowActions', 'bulkActions', 'selectable'])
        ->and($schema['detail']['sections'])->toHaveCount(1);
});
