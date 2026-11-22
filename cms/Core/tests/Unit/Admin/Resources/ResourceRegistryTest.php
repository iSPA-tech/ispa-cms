<?php

use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;

uses(\Cms\Core\Tests\TestCase::class);

it('registers, checks, makes and lists resources', function () {
    $registry = new ResourceRegistry(app());
    $registry->register('helpdesk/organizations', OrganizationsFixtureResource::class);

    expect($registry->has('helpdesk/organizations'))->toBeTrue()
        ->and($registry->has('other'))->toBeFalse()
        ->and($registry->keys())->toBe(['helpdesk/organizations'])
        ->and($registry->make('helpdesk/organizations'))->toBeInstanceOf(OrganizationsFixtureResource::class);
});

it('rejects duplicate keys', function () {
    $registry = new ResourceRegistry(app());
    $registry->register('a', OrganizationsFixtureResource::class);

    expect(fn () => $registry->register('a', OrganizationsFixtureResource::class))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects classes that are not resources', function () {
    expect(fn () => (new ResourceRegistry(app()))->register('a', stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

it('fails on unknown keys', function () {
    expect(fn () => (new ResourceRegistry(app()))->make('missing'))
        ->toThrow(InvalidArgumentException::class);
});

it('is a container singleton', function () {
    expect(app(ResourceRegistry::class))->toBe(app(ResourceRegistry::class));
});
