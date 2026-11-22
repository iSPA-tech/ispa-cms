<?php

use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;
use Cms\Core\Tests\Fixtures\Resources\SecuredFixtureResource;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(\Cms\Core\Tests\TestCase::class);

function schemaUser(int $id): User
{
    $user = new User;
    $user->id = $id;

    return $user;
}

beforeEach(function () {
    $p = 'api/v2/admin/';
    Route::get($p.'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.index');
    Route::post($p.'helpdesk/organizations', fn () => null)->name('helpdesk.organizations.store');
    Route::get($p.'helpdesk/organizations/{uuid}', fn () => null)->name('helpdesk.organizations.show');
    Route::post($p.'helpdesk/organizations/{uuid}/status', fn () => null)->name('helpdesk.organizations.status');
    Route::post($p.'helpdesk/organizations/{uuid}/invite', fn () => null)->name('helpdesk.organizations.invite');
    Route::get($p.'test/secured', fn () => null)->name('test.secured.index');
    Route::post($p.'test/secured', fn () => null)->name('test.secured.store');
    Route::put($p.'test/secured/{uuid}', fn () => null)->name('test.secured.update');
    Route::get($p.'test/secured/{uuid}', fn () => null)->name('test.secured.show');
    Route::delete($p.'test/secured/{uuid}', fn () => null)->name('test.secured.archive');
    Route::post($p.'test/secured/{uuid}/note', fn () => null)->name('test.secured.note');
    Route::get('web/outside', fn () => null)->name('test.outside');
    app('router')->getRoutes()->refreshNameLookups();

    Gate::define('secret.view', fn ($user) => $user->id === 1);
    Gate::define('secured.manage', fn ($user) => $user->id === 1);
});

it('exposes a schema version constant', function () {
    expect(SchemaBuilder::VERSION)->toBe(1);
});

it('builds the exact schema for the organizations fixture', function () {
    $status = ['field' => 'status', 'type' => 'status', 'label' => 'status', 'rules' => [], 'options' => [
        ['value' => 'active', 'label' => 'status_active'],
        ['value' => 'suspended', 'label' => 'status_suspended'],
    ], 'filterable' => true];
    $name = ['field' => 'name', 'type' => 'text', 'label' => 'name', 'rules' => ['required' => true, 'max' => 191], 'searchable' => true];

    $schema = (new SchemaBuilder)->build(new OrganizationsFixtureResource, schemaUser(2));

    expect($schema)->toEqual([
        'schema_version' => 1,
        'resource' => 'helpdesk/organizations',
        'list' => [
            'endpoint' => ['route' => 'helpdesk.organizations.index', 'path' => 'helpdesk/organizations', 'method' => 'GET', 'adapter' => 'ispa-list-v1'],
            'search' => ['param' => 'q', 'placeholder' => 'helpdesk.search'],
            'filters' => [
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => $status['options']],
                ['field' => 'created_via', 'type' => 'enum', 'label' => 'created_via', 'options' => [
                    ['value' => 'admin', 'label' => 'created_via_admin'],
                    ['value' => 'portal', 'label' => 'created_via_portal'],
                ]],
            ],
            'columns' => [
                ['field' => 'name', 'type' => 'text', 'label' => 'name', 'link' => 'detail'],
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => $status['options']],
                ['field' => 'members_count', 'type' => 'count', 'label' => 'members_count'],
                ['field' => 'created_at', 'type' => 'datetime', 'label' => 'created_at'],
            ],
        ],
        'form' => [
            'create' => [
                'endpoint' => ['route' => 'helpdesk.organizations.store', 'path' => 'helpdesk/organizations', 'method' => 'POST'],
                'fields' => [
                    $name,
                    ['field' => 'owner_email', 'type' => 'email', 'label' => 'owner_email', 'rules' => ['required' => true, 'max' => 191]],
                ],
                'success_message' => 'helpdesk.created',
            ],
        ],
        'detail' => [
            'endpoint' => ['route' => 'helpdesk.organizations.show', 'path' => 'helpdesk/organizations/:uuid', 'method' => 'GET'],
            'sections' => [
                $name,
                $status,
                ['field' => 'created_at', 'type' => 'datetime', 'label' => 'created_at', 'rules' => [], 'value_format' => 'YYYY-MM-DD HH:mm:ss'],
            ],
            'tabs' => [
                ['name' => 'members', 'label' => 'helpdesk.members', 'widget' => 'members-table'],
                ['name' => 'activity', 'label' => 'helpdesk.activity', 'widget' => 'activity-feed'],
            ],
        ],
        'actions' => [
            [
                'name' => 'suspend',
                'label' => 'helpdesk.suspend',
                'endpoint' => ['route' => 'helpdesk.organizations.status', 'path' => 'helpdesk/organizations/:uuid/status', 'method' => 'POST'],
                'payload' => ['status' => 'suspended'],
                'visible' => ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
            ],
            [
                'name' => 'activate',
                'label' => 'helpdesk.activate',
                'endpoint' => ['route' => 'helpdesk.organizations.status', 'path' => 'helpdesk/organizations/:uuid/status', 'method' => 'POST'],
                'payload' => ['status' => 'active'],
                'visible' => ['field' => 'status', 'op' => 'eq', 'value' => 'suspended'],
            ],
            [
                'name' => 'invite',
                'label' => 'helpdesk.invite',
                'endpoint' => ['route' => 'helpdesk.organizations.invite', 'path' => 'helpdesk/organizations/:uuid/invite', 'method' => 'POST'],
                'fields' => [
                    ['field' => 'email', 'type' => 'email', 'label' => 'email', 'rules' => ['required' => true, 'max' => 191]],
                    ['field' => 'role', 'type' => 'enum', 'label' => 'role', 'rules' => ['required' => true], 'options' => [
                        ['value' => 'owner', 'label' => 'role_owner'],
                        ['value' => 'member', 'label' => 'role_member'],
                    ]],
                ],
            ],
        ],
    ]);
});

it('builds the full schema for an allowed user', function () {
    $name = ['field' => 'name', 'type' => 'text', 'label' => 'name', 'rules' => ['required' => true]];
    $secret = ['field' => 'secret', 'type' => 'text', 'label' => 'secret', 'rules' => []];
    $statusOptions = [['value' => 'on', 'label' => 'status_on'], ['value' => 'off', 'label' => 'status_off']];

    $schema = (new SchemaBuilder)->build(new SecuredFixtureResource, schemaUser(1));

    expect($schema)->toEqual([
        'schema_version' => 1,
        'resource' => 'test/secured',
        'list' => [
            'endpoint' => ['route' => 'test.secured.index', 'path' => 'test/secured', 'method' => 'GET', 'adapter' => 'custom-adapter'],
            'filters' => [
                ['field' => 'secret', 'type' => 'text', 'label' => 'secret'],
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => $statusOptions],
            ],
            'columns' => [
                ['field' => 'name', 'type' => 'text', 'label' => 'name'],
                ['field' => 'secret', 'type' => 'text', 'label' => 'secret'],
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => $statusOptions],
            ],
        ],
        'form' => [
            'create' => [
                'endpoint' => ['route' => 'test.secured.store', 'path' => 'test/secured', 'method' => 'POST'],
                'fields' => [$name, $secret],
            ],
            'update' => [
                'endpoint' => ['route' => 'test.secured.update', 'path' => 'test/secured/:uuid', 'method' => 'PUT'],
                'fields' => [$name],
            ],
        ],
        'detail' => [
            'endpoint' => ['route' => 'test.secured.show', 'path' => 'test/secured/:uuid', 'method' => 'GET'],
            'sections' => [$name, $secret],
            'tabs' => [],
        ],
        'actions' => [
            ['name' => 'archive', 'label' => 'archive', 'endpoint' => ['route' => 'test.secured.archive', 'path' => 'test/secured/:uuid', 'method' => 'DELETE']],
            ['name' => 'note', 'label' => 'note', 'endpoint' => ['route' => 'test.secured.note', 'path' => 'test/secured/:uuid/note', 'method' => 'POST'], 'fields' => [
                ['field' => 'memo', 'type' => 'text', 'label' => 'memo', 'rules' => []],
                ['field' => 'hidden', 'type' => 'text', 'label' => 'hidden', 'rules' => []],
            ]],
        ],
    ]);
});

it('removes what a user may not use', function () {
    $name = ['field' => 'name', 'type' => 'text', 'label' => 'name', 'rules' => ['required' => true]];

    $schema = (new SchemaBuilder)->build(new SecuredFixtureResource, schemaUser(2));

    expect($schema)->toEqual([
        'schema_version' => 1,
        'resource' => 'test/secured',
        'list' => [
            'endpoint' => ['route' => 'test.secured.index', 'path' => 'test/secured', 'method' => 'GET', 'adapter' => 'custom-adapter'],
            'filters' => [
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => [['value' => 'on', 'label' => 'status_on'], ['value' => 'off', 'label' => 'status_off']]],
            ],
            'columns' => [
                ['field' => 'name', 'type' => 'text', 'label' => 'name'],
                ['field' => 'status', 'type' => 'status', 'label' => 'status', 'options' => [['value' => 'on', 'label' => 'status_on'], ['value' => 'off', 'label' => 'status_off']]],
            ],
        ],
        'form' => null,
        'detail' => [
            'endpoint' => ['route' => 'test.secured.show', 'path' => 'test/secured/:uuid', 'method' => 'GET'],
            'sections' => [$name],
            'tabs' => [],
        ],
        'actions' => [
            ['name' => 'note', 'label' => 'note', 'endpoint' => ['route' => 'test.secured.note', 'path' => 'test/secured/:uuid/note', 'method' => 'POST'], 'fields' => [
                ['field' => 'memo', 'type' => 'text', 'label' => 'memo', 'rules' => []],
            ]],
        ],
    ]);
});

it('emits null views and no actions when a resource declares none', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'bare';
        }

        public function ability(): string
        {
            return 'bare.view';
        }

        public function fields(): array
        {
            return [Field::text('a')];
        }
    };

    expect((new SchemaBuilder)->build($resource, schemaUser(1)))->toBe([
        'schema_version' => 1, 'resource' => 'bare', 'list' => null, 'form' => null, 'detail' => null, 'actions' => [],
    ]);
});

it('exports a per-context success message over the form default', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'secured';
        }

        public function ability(): string
        {
            return 'bare.view';
        }

        public function fields(): array
        {
            return [Field::text('name')];
        }

        public function form(): ?\Cms\Core\Admin\Resources\FormView
        {
            return \Cms\Core\Admin\Resources\FormView::create(['name'])
                ->update(['name'])
                ->endpoint('test.secured.store')
                ->updateEndpoint('test.secured.update')
                ->successMessage('created_and_invited')
                ->successMessage('saved', 'update');
        }
    };

    $form = (new SchemaBuilder)->build($resource, schemaUser(1))['form'];

    expect($form['create']['success_message'])->toBe('created_and_invited')
        ->and($form['update']['success_message'])->toBe('saved');
});

it('keeps a literal :uuid placeholder in paths', function () {
    $schema = (new SchemaBuilder)->build(new OrganizationsFixtureResource, schemaUser(2));

    expect($schema['detail']['endpoint']['path'])->toContain(':uuid')->not->toContain('%3A');
});

it('throws on an unknown route name', function () {
    Route::getRoutes()->refreshNameLookups();
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'x';
        }

        public function ability(): string
        {
            return 'x';
        }

        public function fields(): array
        {
            return [Field::text('a')];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return \Cms\Core\Admin\Resources\ListView::make()->columns(['a'])->endpoint('no.such.route');
        }
    };

    expect(fn () => (new SchemaBuilder)->build($resource, schemaUser(1)))->toThrow(InvalidArgumentException::class);
});

it('throws when a route is outside the admin api prefix', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'x';
        }

        public function ability(): string
        {
            return 'x';
        }

        public function fields(): array
        {
            return [Field::text('a')];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return \Cms\Core\Admin\Resources\ListView::make()->columns(['a'])->endpoint('test.outside');
        }
    };

    expect(fn () => (new SchemaBuilder)->build($resource, schemaUser(1)))->toThrow(LogicException::class);
});

it('throws on an invalid condition', function () {
    $field = Field::text('a');
    $ref = new ReflectionProperty($field, 'visibleWhen');
    $ref->setValue($field, ['field' => 'a', 'op' => 'bogus']);

    $resource = new class($field) extends \Cms\Core\Admin\Resources\Resource
    {
        public function __construct(private Field $f) {}

        public function key(): string
        {
            return 'x';
        }

        public function ability(): string
        {
            return 'x';
        }

        public function fields(): array
        {
            return [$this->f];
        }
    };

    expect(fn () => (new SchemaBuilder)->build($resource, schemaUser(1)))->toThrow(InvalidArgumentException::class);
});

/**
 * Build a bare resource with a list view for the width / allLabel / labels tests.
 */
function listSchemaResource(Field $status, ?\Cms\Core\Admin\Resources\ListView $list = null): \Cms\Core\Admin\Resources\Resource
{
    return new class($status, $list) extends \Cms\Core\Admin\Resources\Resource
    {
        public function __construct(private Field $status, private ?\Cms\Core\Admin\Resources\ListView $view) {}

        public function key(): string
        {
            return 'bare';
        }

        public function ability(): string
        {
            return 'bare.view';
        }

        public function fields(): array
        {
            return [Field::text('name'), $this->status];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return $this->view;
        }
    };
}

it('emits width, allLabel and labels only when set', function () {
    $view = \Cms\Core\Admin\Resources\ListView::make()
        ->columns(['name', 'status'])->filters(['status'])
        ->endpoint('helpdesk.organizations.index')
        ->labels(['title' => 'x.title', 'empty' => 'x.empty']);
    $status = Field::enum('status', ['a'])->width(90)->filterAllLabel('x.all');

    $list = (new SchemaBuilder)->build(listSchemaResource($status, $view), schemaUser(1))['list'];

    expect($list['columns'][0])->not->toHaveKey('width')
        ->and($list['columns'][1]['width'])->toBe(90)
        ->and($list['filters'][0]['allLabel'])->toBe('x.all')
        ->and($list['labels'])->toBe(['title' => 'x.title', 'empty' => 'x.empty']);
});

it('omits width, allLabel and labels when none are set', function () {
    $view = \Cms\Core\Admin\Resources\ListView::make()
        ->columns(['name', 'status'])->filters(['status'])
        ->endpoint('helpdesk.organizations.index');

    $list = (new SchemaBuilder)->build(listSchemaResource(Field::enum('status', ['a']), $view), schemaUser(1))['list'];

    expect($list)->not->toHaveKey('labels')
        ->and($list['columns'][1])->not->toHaveKey('width')
        ->and($list['filters'][0])->not->toHaveKey('allLabel');

    $fixture = (new SchemaBuilder)->build(new OrganizationsFixtureResource, schemaUser(2))['list'];
    expect($fixture)->not->toHaveKey('labels');
    foreach ($fixture['columns'] as $column) {
        expect($column)->not->toHaveKey('width');
    }
    foreach ($fixture['filters'] as $filter) {
        expect($filter)->not->toHaveKey('allLabel');
    }
});

it('rejects unknown or empty list labels', function () {
    $view = \Cms\Core\Admin\Resources\ListView::make();
    expect(fn () => $view->labels(['bogus' => 'x']))->toThrow(InvalidArgumentException::class, 'bogus');
    expect(fn () => $view->labels(['title' => '']))->toThrow(InvalidArgumentException::class);
    expect($view->getLabels())->toBe([]);
});

/**
 * Build a bare resource with optional form and detail views for the label tests.
 */
function viewSchemaResource(?\Cms\Core\Admin\Resources\ListView $list, ?\Cms\Core\Admin\Resources\FormView $form, ?\Cms\Core\Admin\Resources\DetailView $detail): \Cms\Core\Admin\Resources\Resource
{
    return new class($list, $form, $detail) extends \Cms\Core\Admin\Resources\Resource
    {
        public function __construct(private ?\Cms\Core\Admin\Resources\ListView $l, private ?\Cms\Core\Admin\Resources\FormView $f, private ?\Cms\Core\Admin\Resources\DetailView $d) {}

        public function key(): string
        {
            return 'bare';
        }

        public function ability(): string
        {
            return 'bare.view';
        }

        public function fields(): array
        {
            return [Field::text('name')];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return $this->l;
        }

        public function form(): ?\Cms\Core\Admin\Resources\FormView
        {
            return $this->f;
        }

        public function detail(): ?\Cms\Core\Admin\Resources\DetailView
        {
            return $this->d;
        }
    };
}

it('emits form labels, detail labels and the breadcrumb parent only when set', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])->endpoint('helpdesk.organizations.index')
        ->breadcrumbParent('helpdesk', 'nav.a.helpdesk');
    $form = \Cms\Core\Admin\Resources\FormView::create(['name'])->endpoint('helpdesk.organizations.store')
        ->labels(['title' => 'x.new', 'hint' => 'x.hint', 'submit' => 'x.create', 'required' => 'x.required']);
    $detail = \Cms\Core\Admin\Resources\DetailView::make()->endpoint('helpdesk.organizations.show')
        ->labels(['title' => 'x.drawer {name}']);

    $schema = (new SchemaBuilder)->build(viewSchemaResource($list, $form, $detail), schemaUser(1));

    expect($schema['list']['breadcrumbParent'])->toBe(['route' => 'helpdesk', 'label' => 'nav.a.helpdesk'])
        ->and($schema['form']['labels'])->toBe(['title' => 'x.new', 'hint' => 'x.hint', 'submit' => 'x.create', 'required' => 'x.required'])
        ->and($schema['form'])->toHaveKey('create')
        ->and($schema['detail']['labels'])->toBe(['title' => 'x.drawer {name}']);
});

it('omits form labels, detail labels and the breadcrumb parent when none are set', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])->endpoint('helpdesk.organizations.index');
    $form = \Cms\Core\Admin\Resources\FormView::create(['name'])->endpoint('helpdesk.organizations.store');
    $detail = \Cms\Core\Admin\Resources\DetailView::make()->endpoint('helpdesk.organizations.show');

    $schema = (new SchemaBuilder)->build(viewSchemaResource($list, $form, $detail), schemaUser(1));

    expect($schema['list'])->not->toHaveKey('breadcrumbParent')
        ->and($schema['form'])->not->toHaveKey('labels')
        ->and($schema['detail'])->not->toHaveKey('labels');

    $fixture = (new SchemaBuilder)->build(new OrganizationsFixtureResource, schemaUser(2));
    expect($fixture['list'])->not->toHaveKey('breadcrumbParent')
        ->and($fixture['form'])->not->toHaveKey('labels')
        ->and($fixture['detail'])->not->toHaveKey('labels');
});

it('does not emit form labels when the user may not use the form', function () {
    $form = \Cms\Core\Admin\Resources\FormView::create(['name'])->endpoint('helpdesk.organizations.store')
        ->ability('secured.manage')->labels(['title' => 'x.new']);

    $schema = (new SchemaBuilder)->build(viewSchemaResource(null, $form, null), schemaUser(2));

    expect($schema['form'])->toBeNull();
});

it('rejects unknown or empty form and detail labels and an empty breadcrumb parent', function () {
    $form = \Cms\Core\Admin\Resources\FormView::make();
    expect(fn () => $form->labels(['required' => '']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $form->labels(['bogus' => 'x']))->toThrow(InvalidArgumentException::class, 'bogus');
    expect(fn () => $form->labels(['title' => '']))->toThrow(InvalidArgumentException::class);
    expect($form->getLabels())->toBe([]);

    $detail = \Cms\Core\Admin\Resources\DetailView::make();
    expect(fn () => $detail->labels(['hint' => 'x']))->toThrow(InvalidArgumentException::class, 'hint');
    expect(fn () => $detail->labels(['title' => '']))->toThrow(InvalidArgumentException::class);
    expect($detail->getLabels())->toBe([]);

    $list = \Cms\Core\Admin\Resources\ListView::make();
    expect(fn () => $list->breadcrumbParent('', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $list->breadcrumbParent('helpdesk', ''))->toThrow(InvalidArgumentException::class);
    expect($list->getBreadcrumbParent())->toBeNull();
});

it('emits the breadcrumb parent icon only when given', function () {
    $with = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])->endpoint('helpdesk.organizations.index')
        ->breadcrumbParent('helpdesk', 'nav.a.helpdesk', 'help-circle-outline');
    $without = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])->endpoint('helpdesk.organizations.index')
        ->breadcrumbParent('helpdesk', 'nav.a.helpdesk');

    $a = (new SchemaBuilder)->build(viewSchemaResource($with, null, null), schemaUser(1));
    $b = (new SchemaBuilder)->build(viewSchemaResource($without, null, null), schemaUser(1));

    expect($a['list']['breadcrumbParent'])->toBe(['route' => 'helpdesk', 'label' => 'nav.a.helpdesk', 'icon' => 'help-circle-outline'])
        ->and($b['list']['breadcrumbParent'])->toBe(['route' => 'helpdesk', 'label' => 'nav.a.helpdesk'])
        ->and(fn () => \Cms\Core\Admin\Resources\ListView::make()->breadcrumbParent('helpdesk', 'x', ''))->toThrow(InvalidArgumentException::class);
});

it('emits an action submit label only when set', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'helpdesk/submit-label';
        }

        public function ability(): string
        {
            return 'helpdesk.agent';
        }

        public function fields(): array
        {
            return [\Cms\Core\Admin\Resources\Field::text('name')];
        }

        public function actions(): array
        {
            return [
                \Cms\Core\Admin\Resources\Action::make('a')->label('x.a')->endpoint('helpdesk.organizations.store')->submitLabel('x.send'),
                \Cms\Core\Admin\Resources\Action::make('b')->label('x.b')->endpoint('helpdesk.organizations.store'),
            ];
        }
    };

    $actions = (new SchemaBuilder)->build($resource, schemaUser(1))['actions'];

    expect($actions[0]['submitLabel'])->toBe('x.send')
        ->and($actions[1])->not->toHaveKey('submitLabel')
        ->and(fn () => \Cms\Core\Admin\Resources\Action::make('c')->submitLabel(''))->toThrow(InvalidArgumentException::class);
});

/**
 * Build a Cms actor whose permission keys are fixed, without touching the database.
 *
 * @param  array<int, string>  $keys
 */
function visibilityActor(array $keys): \App\Models\User
{
    return new class($keys) extends \App\Models\User
    {
        /** @param array<int, string> $keys */
        public function __construct(private array $keys = [])
        {
            parent::__construct();
        }

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function loadMissing($relations)
        {
            return $this;
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
}

/** Register the scoped resource `fixture/records` hiding `owner_user_id` unless `fixture.secret` is held. */
function registerVisibilityScope(): void
{
    $registry = new \Cms\Core\Services\DataScope\ScopedResourceRegistry;
    $registry->register(
        \Cms\Core\Services\DataScope\ScopedResource::make('fixture/records', \Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord::class, 'fixture.view')
            ->hideUnless('owner_user_id', 'fixture.secret')
            ->hideUnless('contact.email', 'fixture.secret')
    );
    app()->instance(\Cms\Core\Services\DataScope\ScopedResourceRegistry::class, $registry);
    app()->forgetInstance(\Cms\Core\Services\DataScope\DataScope::class);
}

/** Resource linked to the scoped resource `fixture/records` with a hidden-able field in every view. */
function visibilityResource(): \Cms\Core\Admin\Resources\Resource
{
    return new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'fixture/records';
        }

        public function ability(): string
        {
            return 'fixture.view';
        }

        public function scopedResource(): ?string
        {
            return 'fixture/records';
        }

        public function fields(): array
        {
            return [
                Field::text('title'),
                Field::text('owner_user_id'),
            ];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return \Cms\Core\Admin\Resources\ListView::make()
                ->columns(['title', 'owner_user_id'])
                ->filters(['owner_user_id'])
                ->endpoint('helpdesk.organizations.index');
        }

        public function form(): ?\Cms\Core\Admin\Resources\FormView
        {
            return \Cms\Core\Admin\Resources\FormView::create(['title', 'owner_user_id'])->endpoint('helpdesk.organizations.store');
        }

        public function detail(): ?\Cms\Core\Admin\Resources\DetailView
        {
            return \Cms\Core\Admin\Resources\DetailView::make()
                ->endpoint('helpdesk.organizations.show')
                ->sections(['title', 'owner_user_id']);
        }

        public function actions(): array
        {
            return [
                \Cms\Core\Admin\Resources\Action::make('assign')->endpoint('helpdesk.organizations.invite')
                    ->fields([Field::text('memo'), Field::text('owner_user_id')]),
            ];
        }
    };
}

it('drops a field the data scope hides from every view and action input', function () {
    registerVisibilityScope();

    $schema = (new SchemaBuilder)->build(visibilityResource(), visibilityActor([]));

    expect(array_column($schema['list']['columns'], 'field'))->toBe(['title'])
        ->and($schema['list']['filters'])->toBe([])
        ->and(array_column($schema['form']['create']['fields'], 'field'))->toBe(['title'])
        ->and(array_column($schema['detail']['sections'], 'field'))->toBe(['title'])
        ->and(array_column($schema['actions'][0]['fields'], 'field'))->toBe(['memo']);
});

it('keeps the field for an actor holding the data scope ability', function () {
    registerVisibilityScope();

    $schema = (new SchemaBuilder)->build(visibilityResource(), visibilityActor(['fixture.secret']));

    expect(array_column($schema['list']['columns'], 'field'))->toBe(['title', 'owner_user_id'])
        ->and(array_column($schema['list']['filters'], 'field'))->toBe(['owner_user_id'])
        ->and(array_column($schema['form']['create']['fields'], 'field'))->toBe(['title', 'owner_user_id'])
        ->and(array_column($schema['detail']['sections'], 'field'))->toBe(['title', 'owner_user_id'])
        ->and(array_column($schema['actions'][0]['fields'], 'field'))->toBe(['memo', 'owner_user_id']);
});

it('treats a non Cms user as no actor and hides every marked field', function () {
    registerVisibilityScope();

    $schema = (new SchemaBuilder)->build(visibilityResource(), schemaUser(1));

    expect(array_column($schema['list']['columns'], 'field'))->toBe(['title']);
});

it('shows the same visible fields as DataScope::strip on a row', function () {
    registerVisibilityScope();
    $row = ['title' => 'T', 'owner_user_id' => 7, 'contact' => ['email' => 'a@b.test', 'name' => 'A']];

    foreach ([[], ['fixture.secret']] as $keys) {
        $actor = visibilityActor($keys);
        $columns = array_column((new SchemaBuilder)->build(visibilityResource(), $actor)['list']['columns'], 'field');
        $stripped = app(\Cms\Core\Services\DataScope\DataScope::class)->strip($row, 'fixture/records', $actor);

        expect($columns)->toBe(array_values(array_intersect(['title', 'owner_user_id'], array_keys($stripped))));
    }
});

it('does not consult the data scope for a resource without a scoped resource', function () {
    app()->instance(\Cms\Core\Services\DataScope\ScopedResourceRegistry::class, new \Cms\Core\Services\DataScope\ScopedResourceRegistry);
    app()->forgetInstance(\Cms\Core\Services\DataScope\DataScope::class);

    $schema = (new SchemaBuilder)->build(new SecuredFixtureResource, schemaUser(1));

    expect($schema['resource'])->toBe('test/secured');
});

/**
 * Anonymous resource with the given list view and actions for the row/bulk action tests.
 *
 * @param  array<int, \Cms\Core\Admin\Resources\Action>  $actions
 */
function bulkFixtureResource(?\Cms\Core\Admin\Resources\ListView $list, array $actions): \Cms\Core\Admin\Resources\Resource
{
    return new class($list, $actions) extends \Cms\Core\Admin\Resources\Resource
    {
        public function __construct(private ?\Cms\Core\Admin\Resources\ListView $listView, private array $actionList) {}

        public function key(): string
        {
            return 'helpdesk/bulk';
        }

        public function ability(): string
        {
            return 'helpdesk.agent';
        }

        public function fields(): array
        {
            return [Field::text('name')];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return $this->listView;
        }

        public function actions(): array
        {
            return $this->actionList;
        }
    };
}

it('exports danger, bulk, after and refresh only when set', function () {
    $A = \Cms\Core\Admin\Resources\Action::class;
    $resource = bulkFixtureResource(null, [
        $A::make('plain')->label('x.plain')->endpoint('helpdesk.organizations.store'),
        $A::make('purge')->label('x.purge')->endpoint('helpdesk.organizations.store')
            ->danger()->bulk()->after('reloadCounters')->refresh('list'),
    ]);

    $actions = (new SchemaBuilder)->build($resource, schemaUser(1))['actions'];

    expect($actions[0])->not->toHaveKeys(['danger', 'bulk', 'after', 'refresh'])
        ->and($actions[1])->toMatchArray(['danger' => true, 'bulk' => true, 'after' => 'reloadCounters', 'refresh' => 'list']);
});

it('refuses an invalid refresh target', function () {
    expect(fn () => \Cms\Core\Admin\Resources\Action::make('a')->refresh('page'))->toThrow(InvalidArgumentException::class);
    foreach (['list', 'record', 'close'] as $target) {
        expect(\Cms\Core\Admin\Resources\Action::make('a')->refresh($target)->getRefresh())->toBe($target);
    }
});

it('exports row actions, bulk actions and selectable of the list', function () {
    $A = \Cms\Core\Admin\Resources\Action::class;
    $list = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])
        ->endpoint('helpdesk.organizations.index')
        ->rowActions(['one'])->bulkActions(['many'])->selectable();
    $resource = bulkFixtureResource($list, [
        $A::make('one')->label('x.one')->endpoint('helpdesk.organizations.status'),
        $A::make('many')->label('x.many')->endpoint('helpdesk.organizations.store')->bulk(),
    ]);

    $out = (new SchemaBuilder)->build($resource, schemaUser(1))['list'];

    expect($out)->toMatchArray(['rowActions' => ['one'], 'bulkActions' => ['many'], 'selectable' => true]);
});

it('omits row actions, bulk actions and selectable when unset', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])->endpoint('helpdesk.organizations.index');

    $out = (new SchemaBuilder)->build(bulkFixtureResource($list, []), schemaUser(1))['list'];

    expect($out)->not->toHaveKeys(['rowActions', 'bulkActions', 'selectable']);
});

it('refuses a row action that is not a declared action', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->endpoint('helpdesk.organizations.index')->rowActions(['ghost']);

    expect(fn () => (new SchemaBuilder)->build(bulkFixtureResource($list, []), schemaUser(1)))
        ->toThrow(InvalidArgumentException::class, 'Unknown row action [ghost]');
});

it('refuses a bulk action that is not a declared action', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->endpoint('helpdesk.organizations.index')->bulkActions(['ghost']);

    expect(fn () => (new SchemaBuilder)->build(bulkFixtureResource($list, []), schemaUser(1)))
        ->toThrow(InvalidArgumentException::class, 'Unknown bulk action [ghost]');
});

it('refuses a bulk action name that is not marked bulk', function () {
    $A = \Cms\Core\Admin\Resources\Action::class;
    $list = \Cms\Core\Admin\Resources\ListView::make()->endpoint('helpdesk.organizations.index')->bulkActions(['one']);
    $resource = bulkFixtureResource($list, [$A::make('one')->label('x.one')->endpoint('helpdesk.organizations.store')]);

    expect(fn () => (new SchemaBuilder)->build($resource, schemaUser(1)))
        ->toThrow(InvalidArgumentException::class, 'is not marked bulk');
});

it('refuses a bulk action whose endpoint path contains :uuid', function () {
    $A = \Cms\Core\Admin\Resources\Action::class;
    $resource = bulkFixtureResource(null, [
        $A::make('many')->label('x.many')->endpoint('helpdesk.organizations.status')->bulk(),
    ]);

    expect(fn () => (new SchemaBuilder)->build($resource, schemaUser(1)))
        ->toThrow(InvalidArgumentException::class, ':uuid');
});

it('accepts and exports the laravel-paginate-v1 adapter', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->columns(['name'])
        ->endpoint('helpdesk.organizations.index', 'laravel-paginate-v1');

    $out = (new SchemaBuilder)->build(bulkFixtureResource($list, []), schemaUser(1))['list'];

    expect($out['endpoint']['adapter'])->toBe('laravel-paginate-v1');
});

it('keeps accepting any adapter name as today (adapters are not validated)', function () {
    $list = \Cms\Core\Admin\Resources\ListView::make()->endpoint('helpdesk.organizations.index', 'custom-adapter');

    expect((new SchemaBuilder)->build(bulkFixtureResource($list, []), schemaUser(1))['list']['endpoint']['adapter'])->toBe('custom-adapter');
});

it('keeps the secondary field and the date-range bounds on list columns', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'helpdesk/columns';
        }

        public function ability(): string
        {
            return 'helpdesk.agent';
        }

        public function fields(): array
        {
            return [
                Field::text('name')->secondary('code'),
                Field::dateRange('timeline', 'start_date', 'end_date'),
            ];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return \Cms\Core\Admin\Resources\ListView::make()->columns(['name', 'timeline'])
                ->endpoint('helpdesk.organizations.index');
        }
    };

    $columns = (new SchemaBuilder)->build($resource, schemaUser(1))['list']['columns'];

    expect($columns[0]['secondary'])->toBe('code')
        ->and($columns[0])->not->toHaveKeys(['start', 'end'])
        ->and($columns[1])->toMatchArray(['start' => 'start_date', 'end' => 'end_date'])
        ->and($columns[1])->not->toHaveKey('secondary');
});

it('copies the field options onto list columns', function () {
    $resource = new class extends \Cms\Core\Admin\Resources\Resource
    {
        public function key(): string
        {
            return 'helpdesk/options';
        }

        public function ability(): string
        {
            return 'helpdesk.agent';
        }

        public function fields(): array
        {
            return [Field::status('flag', [true, false])->options([1 => 'on_label', 0 => 'off_label'])];
        }

        public function list(): ?\Cms\Core\Admin\Resources\ListView
        {
            return \Cms\Core\Admin\Resources\ListView::make()->columns(['flag'])->endpoint('helpdesk.organizations.index');
        }
    };

    $column = (new SchemaBuilder)->build($resource, schemaUser(1))['list']['columns'][0];

    expect($column['options'])->toBe([['value' => true, 'label' => 'on_label'], ['value' => false, 'label' => 'off_label']]);
});

it('exports the form after hook per context only when set', function () {
    $plain = \Cms\Core\Admin\Resources\FormView::create(['name'])->update(['name'])->endpoint('helpdesk.organizations.store')->updateEndpoint('helpdesk.organizations.store');
    $hooked = \Cms\Core\Admin\Resources\FormView::create(['name'])->update(['name'])->endpoint('helpdesk.organizations.store')->updateEndpoint('helpdesk.organizations.store')
        ->after('x.refresh');

    $plainSchema = (new SchemaBuilder)->build(viewSchemaResource(null, $plain, null), schemaUser(1));
    $hookedSchema = (new SchemaBuilder)->build(viewSchemaResource(null, $hooked, null), schemaUser(1));

    expect($plainSchema['form']['create'])->not->toHaveKey('after')
        ->and($plainSchema['form']['update'])->not->toHaveKey('after')
        ->and($hookedSchema['form']['create']['after'])->toBe('x.refresh')
        ->and($hookedSchema['form']['update']['after'])->toBe('x.refresh')
        ->and($hooked->getAfter())->toBe('x.refresh')
        ->and($plain->getAfter())->toBeNull();
});

it('rejects an empty form after hook name', function () {
    expect(fn () => \Cms\Core\Admin\Resources\FormView::make()->after(''))->toThrow(InvalidArgumentException::class);
});


it('emits detail.timeline only for a registered record type the user may view', function () {
    app(\Cms\Core\Records\RecordRegistry::class)->register(new \Cms\Core\Records\RecordType(
        'fixture.timeline', \Illuminate\Foundation\Auth\User::class, 'secret.view', 'secured.manage',
    ));
    $build = fn (string $type, int $userId): array => (new SchemaBuilder)->build(viewSchemaResource(
        null, null, \Cms\Core\Admin\Resources\DetailView::make()->endpoint('helpdesk.organizations.show')->timeline($type),
    ), schemaUser($userId));

    expect($build('fixture.timeline', 1)['detail']['timeline'])->toBe(['record_type' => 'fixture.timeline'])
        ->and($build('fixture.timeline', 2)['detail'])->not->toHaveKey('timeline')
        ->and($build('fixture.missing', 1)['detail'])->not->toHaveKey('timeline');

    $plain = (new SchemaBuilder)->build(viewSchemaResource(
        null, null, \Cms\Core\Admin\Resources\DetailView::make()->endpoint('helpdesk.organizations.show'),
    ), schemaUser(1));
    expect($plain['detail'])->not->toHaveKey('timeline');
});
