<?php

use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;

it('builds create rules in declared order', function () {
    expect((new OrganizationsFixtureResource)->rules('create'))->toBe([
        'name' => ['required', 'string', 'max:191'],
        'owner_email' => ['required', 'email', 'max:191'],
    ]);
});

it('builds action rules', function () {
    $rules = (new OrganizationsFixtureResource)->rules('invite');

    expect(array_keys($rules))->toBe(['email', 'role'])
        ->and($rules['email'])->toBe(['required', 'email', 'max:191'])
        ->and($rules['role'][0])->toBe('required')
        ->and((string) $rules['role'][1])->toBe('in:"owner","member"');
});

it('builds rule set rules', function () {
    $rules = (new OrganizationsFixtureResource)->rules('status');

    expect(array_keys($rules))->toBe(['status'])
        ->and($rules['status'][0])->toBe('required')
        ->and((string) $rules['status'][1])->toBe('in:"active","suspended"');
});

it('rejects unknown contexts', function () {
    $r = new OrganizationsFixtureResource;

    expect(fn () => $r->rules('update'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->rules('nope'))->toThrow(InvalidArgumentException::class);
});

it('looks up fields by name', function () {
    $r = new OrganizationsFixtureResource;

    expect($r->field('name'))->toBeInstanceOf(Field::class)
        ->and(fn () => $r->field('nope'))->toThrow(InvalidArgumentException::class);
});

it('keeps views and actions in declared order', function () {
    $r = new OrganizationsFixtureResource;

    expect($r->list()->getColumns())->toBe(['name', 'status', 'members_count', 'created_at'])
        ->and($r->list()->getFilters())->toBe(['status', 'created_via'])
        ->and($r->list()->getAdapter())->toBe('ispa-list-v1')
        ->and($r->list()->hasDetailLink())->toBeTrue()
        ->and($r->detail()->getSections())->toBe(['name', 'status', 'created_at'])
        ->and(array_column($r->detail()->getTabs(), 'name'))->toBe(['members', 'activity'])
        ->and(array_map(fn ($a) => $a->name(), $r->actions()))->toBe(['suspend', 'activate', 'invite'])
        ->and($r->form()->contexts())->toBe(['create'])
        ->and($r->form()->fieldsFor('update'))->toBeNull();
});

it('rejects unsupported action methods and bad fields', function () {
    expect(fn () => Action::make('x')->endpoint('r', [], 'PATCH'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Action::make('x')->fields(['nope']))->toThrow(InvalidArgumentException::class);
    expect(Action::make('x')->endpoint('r', ['a' => 1], 'DELETE')->getMethod())->toBe('DELETE');
});
