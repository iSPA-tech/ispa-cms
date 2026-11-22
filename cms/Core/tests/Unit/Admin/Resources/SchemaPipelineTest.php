<?php

use App\Models\User;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\Pipeline\CustomFieldsStage;
use Cms\Core\Admin\Resources\Pipeline\DefinitionStage;
use Cms\Core\Admin\Resources\Pipeline\OverlayStage;
use Cms\Core\Admin\Resources\Pipeline\PermissionStage;
use Cms\Core\Admin\Resources\Pipeline\SchemaContext;
use Cms\Core\Admin\Resources\Pipeline\SchemaStage;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Organizations-like resource with list, create and update forms and detail; custom fields off. */
class PipePlainResource extends Resource
{
    /** Resource key (the opted-in resources share it, so attribute rows exist for it). */
    public function key(): string
    {
        return 'pipetest/organizations';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'pipetest.organizations.view';
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [
            Field::text('name')->required()->max(191)->searchable(),
            Field::status('status', ['active', 'suspended'])->filterable(),
            Field::datetime('created_at'),
        ];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()->columns(['name', 'status', 'created_at'])->search('q', 'helpdesk.search')
            ->filters(['status'])->endpoint('helpdesk.organizations.index')->detailLink();
    }

    /** Form view with create and update contexts. */
    public function form(): ?FormView
    {
        return FormView::create(['name'])->endpoint('helpdesk.organizations.store')
            ->update(['name'])->updateEndpoint('helpdesk.organizations.update');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()->endpoint('helpdesk.organizations.show')->sections(['name', 'status']);
    }
}

/** The same resource opted in to custom fields. */
class PipeOptedResource extends PipePlainResource
{
    /** Opt in. */
    public function supportsCustomFields(): bool
    {
        return true;
    }
}

/** Opted-in resource whose data scope can hide a custom field. */
class PipeScopedResource extends PipeOptedResource
{
    /** Scoped resource key. */
    public function scopedResource(): ?string
    {
        return 'pipetest/organizations';
    }
}

/** Plain user for the unscoped cases. */
function pipeUser(): AuthUser
{
    $user = new AuthUser;
    $user->id = 2;

    return $user;
}

/**
 * Cms user holding the given permission keys.
 *
 * @param  array<int, string>  $keys
 */
function pipeActor(array $keys): User
{
    return new class($keys) extends User
    {
        /** @param array<int, string> $keys */
        public function __construct(private array $keys = [])
        {
            parent::__construct();
        }

        /** Permission keys the actor holds. */
        public function permissionKeys(): array
        {
            return $this->keys;
        }

        /** No relations to load. */
        public function loadMissing($relations)
        {
            return $this;
        }

        /** Never a webmaster. */
        public function isWebmaster(): bool
        {
            return false;
        }
    };
}

/**
 * Define the custom fields: a listed + searchable text field, a filterable select and a plain textarea.
 *
 * @return array<string, \Cms\Core\Models\MetaAttribute>
 */
function pipeDefineFields(): array
{
    $service = app(CustomFieldService::class);
    $labels = ['vi' => 'Nhãn', 'en' => 'Label'];

    $industry = $service->create('pipetest/organizations', [
        'key' => 'industry', 'type' => 'text', 'labels' => $labels, 'show_in_list' => true, 'is_searchable' => true,
    ], null);
    $tier = $service->create('pipetest/organizations', [
        'key' => 'tier', 'type' => 'select', 'labels' => $labels,
        'options' => [['value' => 'gold', 'labels' => $labels], ['value' => 'silver', 'labels' => $labels]],
    ], null);
    $tier->forceFill(['is_filter' => true])->save();
    $note = $service->create('pipetest/organizations', ['key' => 'note', 'type' => 'textarea', 'labels' => $labels], null);

    return ['industry' => $industry, 'tier' => $tier, 'note' => $note];
}

beforeEach(function () {
    $p = 'api/v2/admin/';
    Route::get($p.'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.index');
    Route::post($p.'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.store');
    Route::get($p.'helpdesk/organizations/{uuid}', fn () => null)->name('helpdesk.organizations.show');
    Route::put($p.'helpdesk/organizations/{uuid}', fn () => null)->name('helpdesk.organizations.update');
    app('router')->getRoutes()->refreshNameLookups();

    app(ResourceRegistry::class)->register('pipetest/organizations', PipeOptedResource::class);
});

it('runs the stages in definition, custom fields, overlay, permission order', function () {
    $log = new ArrayObject;
    $spy = fn (string $name): SchemaStage => new class($name, $log) implements SchemaStage
    {
        public function __construct(private string $name, private ArrayObject $log) {}

        public function apply(array $schema, SchemaContext $context): array
        {
            $this->log[] = $this->name;
            $schema['trail'][] = $this->name;

            return $schema;
        }
    };

    $schema = (new SchemaBuilder([$spy('definition'), $spy('custom'), $spy('overlay'), $spy('permission')]))
        ->build(new PipeOptedResource, pipeUser());

    expect($log->getArrayCopy())->toBe(['definition', 'custom', 'overlay', 'permission'])
        ->and($schema['trail'])->toBe($log->getArrayCopy());

    $default = (new ReflectionClass(new SchemaBuilder))->getProperty('stages')->getValue(new SchemaBuilder);
    expect(array_map(fn ($s) => $s::class, $default))->toBe([
        DefinitionStage::class, CustomFieldsStage::class, OverlayStage::class, PermissionStage::class,
    ]);
});

it('adds the custom fields of an opted-in resource to every view', function () {
    pipeDefineFields();

    $schema = (new SchemaBuilder)->build(new PipeOptedResource, pipeUser());

    $createFields = array_column($schema['form']['create']['fields'], 'field');
    $updateFields = array_column($schema['form']['update']['fields'], 'field');
    $custom = ['custom_fields.industry', 'custom_fields.tier', 'custom_fields.note'];

    expect(array_slice($createFields, -3))->toBe($custom)
        ->and(array_slice(array_column($schema['detail']['sections'], 'field'), -3))->toBe($custom)
        ->and(array_slice($updateFields, -3))->toBe($custom);

    $columns = array_column($schema['list']['columns'], 'field');
    expect(end($columns))->toBe('custom_fields.industry')
        ->and(in_array('custom_fields.tier', $columns, true))->toBeFalse()
        ->and($schema['list']['columns'][0]['link'])->toBe('detail')
        ->and(array_column($schema['list']['filters'], 'field'))->toContain('custom_fields.tier')
        ->and(array_column($schema['list']['filters'], 'field'))->not->toContain('custom_fields.industry')
        ->and($schema['list']['search'])->toBe(['param' => 'q', 'placeholder' => 'helpdesk.search', 'fields' => ['custom_fields.industry']]);
});

it('leaves a resource that did not opt in unchanged even when attribute rows exist for its key', function () {
    pipeDefineFields();

    $plain = (new SchemaBuilder)->build(new PipePlainResource, pipeUser());
    $baseline = (new SchemaBuilder([new DefinitionStage, new PermissionStage]))->build(new PipePlainResource, pipeUser());

    expect($plain)->toBe($baseline)
        ->and(json_encode($plain))->not->toContain('custom_fields');
});

it('leaves archived custom fields out', function () {
    $fields = pipeDefineFields();
    app(CustomFieldService::class)->archive($fields['note']);

    $schema = (new SchemaBuilder)->build(new PipeOptedResource, pipeUser());

    expect(array_column($schema['form']['create']['fields'], 'field'))
        ->toContain('custom_fields.industry')
        ->not->toContain('custom_fields.note');
});

it('loads the custom fields with a single query', function () {
    pipeDefineFields();

    DB::enableQueryLog();
    (new SchemaBuilder)->build(new PipeOptedResource, pipeUser());
    // The overlay stage reads its own row; only the custom-field queries are counted here.
    $queries = array_values(array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => ! str_contains($query['query'], 'resource_overlays'),
    ));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

it('removes a custom field the data scope hides from every view', function () {
    pipeDefineFields();
    $registry = new ScopedResourceRegistry;
    $registry->register(
        ScopedResource::make('pipetest/organizations', ScopeFixtureRecord::class, 'fixture.view')
            ->hideUnless('custom_fields.industry', 'fixture.secret')
    );
    app()->instance(ScopedResourceRegistry::class, $registry);
    app()->forgetInstance(\Cms\Core\Services\DataScope\DataScope::class);

    $hidden = (new SchemaBuilder)->build(new PipeScopedResource, pipeActor([]));
    $shown = (new SchemaBuilder)->build(new PipeScopedResource, pipeActor(['fixture.secret']));

    expect(json_encode($hidden))->not->toContain('custom_fields.industry')
        ->and(json_encode($hidden))->toContain('custom_fields.tier')
        ->and($hidden['list']['search']['fields'])->toBe([])
        ->and($hidden['list']['columns'][0]['link'])->toBe('detail')
        ->and(array_column($shown['list']['columns'], 'field'))->toContain('custom_fields.industry')
        ->and($shown['list']['search']['fields'])->toBe(['custom_fields.industry']);
});

it('removes a field registered by an earlier stage when the user lacks its ability', function () {
    $adder = new class implements SchemaStage
    {
        public function apply(array $schema, SchemaContext $context): array
        {
            $field = Field::text('secret_note')->permission('pipe.secret');
            $context->registerField($field);
            $schema['detail']['sections'][] = $field->toSchema();

            return $schema;
        }
    };
    Illuminate\Support\Facades\Gate::define('pipe.secret', fn ($user) => $user->id === 1);

    $stages = fn () => new SchemaBuilder([new DefinitionStage, $adder, new PermissionStage]);
    $user = fn (int $id) => tap(new AuthUser, fn ($u) => $u->id = $id);

    expect(array_column($stages()->build(new PipePlainResource, $user(1))['detail']['sections'], 'field'))->toContain('secret_note')
        ->and(array_column($stages()->build(new PipePlainResource, $user(2))['detail']['sections'], 'field'))->not->toContain('secret_note');
});

it('overlay stage returns the schema unchanged', function () {
    $context = new SchemaContext(new PipeOptedResource, pipeUser(), 'vi');
    $schema = ['schema_version' => 1, 'resource' => 'x', 'list' => null];

    expect((new OverlayStage)->apply($schema, $context))->toBe($schema);
});

it('keeps schema version 1', function () {
    pipeDefineFields();

    expect(SchemaBuilder::VERSION)->toBe(1)
        ->and((new SchemaBuilder)->build(new PipeOptedResource, pipeUser())['schema_version'])->toBe(1);
});

it('resources do not accept custom fields by default', function () {
    expect((new PipePlainResource)->supportsCustomFields())->toBeFalse();
});
