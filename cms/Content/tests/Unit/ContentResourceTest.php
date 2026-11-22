<?php

use App\Enums\ContentStatusEnum;
use Cms\Content\Admin\Resources\ContentResource;
use Cms\Content\Http\Requests\Content\StoreContentRequest;
use Cms\Content\Http\Requests\Content\UpdateContentRequest;
use Cms\Core\Admin\Resources\Condition;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(\Cms\Core\Tests\TestCase::class);

/** Build a bare user with a numeric id used by the Gate stubs below. */
function contentResourceUser(int $id): User
{
    $user = new User;
    $user->id = $id;

    return $user;
}

/** Normalise a Laravel rule list so a pipe string and an array of the same rules compare equal. */
function contentRuleSet(array|string $rules): array
{
    $list = is_string($rules) ? explode('|', $rules) : $rules;
    sort($list);

    return $list;
}

beforeEach(function () {
    // The real Content routes, registered the way the module route provider does (prefix `api`, name `api.`).
    Route::middleware('api')->prefix('api')->name('api.')->group(module_path('Content', 'routes/api.php'));
    app('router')->getRoutes()->refreshNameLookups();

    // User 1 may view only; user 2 may view and update; user 3 has nothing.
    Gate::define('content.view', fn ($user) => in_array($user->id, [1, 2], true));
    Gate::define('content.update', fn ($user) => $user->id === 2);
});

it('builds update rules equal to the UpdateContentRequest rules for the fields the form edits', function () {
    $resource = new ContentResource;
    $update = (new UpdateContentRequest)->rules();

    foreach (['name', 'description', 'video_url'] as $field) {
        expect(contentRuleSet($resource->rules('update')[$field]))->toEqual(contentRuleSet($update[$field]));
    }
    expect(array_keys($resource->rules('update')))->toBe(['name', 'description', 'video_url']);
});

it('is registered in the resource registry by the module provider', function () {
    $registry = app(ResourceRegistry::class);

    expect($registry->has('content/contents'))->toBeTrue();
    expect($registry->make('content/contents'))->toBeInstanceOf(ContentResource::class);
});

it('gives a viewer the list and detail but no form or actions, and an editor the edit form', function () {
    $viewer = (new SchemaBuilder)->build(new ContentResource, contentResourceUser(1));

    expect($viewer['resource'])->toBe('content/contents');
    expect($viewer['form'])->toBeNull();
    expect($viewer['actions'])->toBe([]);
    expect($viewer['list']['endpoint']['path'])->toBe('content');
    expect($viewer['list']['endpoint']['adapter'])->toBe('content-collection-v1');
    expect($viewer['list']['search']['param'])->toBe('keywords');
    expect(array_column($viewer['list']['filters'], 'field'))->toBe(['status', 'is_confirmed']);
    expect($viewer['detail']['endpoint']['method'])->toBe('GET');
    expect($viewer['detail']['endpoint']['path'])->toBe('content/:uuid');

    $editor = (new SchemaBuilder)->build(new ContentResource, contentResourceUser(2));
    expect($editor['form']['create'] ?? null)->toBeNull();
    expect(array_column($editor['form']['update']['fields'], 'field'))->toBe(['name', 'description', 'video_url']);
    expect($editor['form']['update']['endpoint']['method'])->toBe('PUT');
    expect($editor['form']['update']['endpoint']['path'])->toBe('content/:uuid');
});

it('shows each action only to a user holding its ability and addresses records by uuid', function () {
    Gate::define('content.confirm', fn ($user) => $user->id === 4);
    Gate::define('content.delete', fn ($user) => $user->id === 4);
    Gate::define('content.view', fn ($user) => in_array($user->id, [1, 2, 4], true));
    Gate::define('content.viewAny', fn ($user) => in_array($user->id, [1, 2, 4], true));

    $names = fn (int $id): array => array_column(
        (new SchemaBuilder)->build(new ContentResource, contentResourceUser($id))['actions'],
        'name',
    );

    expect($names(1))->toBe([]);
    expect($names(2))->toBe(['publish']);
    expect($names(4))->toBe(['confirm', 'delete']);

    $actions = array_column((new SchemaBuilder)->build(new ContentResource, contentResourceUser(4))['actions'], null, 'name');
    expect($actions['delete']['endpoint']['path'])->toBe('content/:uuid');
    expect($actions['delete']['endpoint']['method'])->toBe('DELETE');
    expect($actions['confirm']['endpoint']['path'])->toBe('content/:uuid/quick');
});

it('hides everything from a user without the list ability', function () {
    $resource = new ContentResource;

    expect(Gate::forUser(contentResourceUser(3))->allows($resource->ability()))->toBeFalse();
    expect($resource->ability())->toBe('content.viewAny');
});

it('declares only valid linkage conditions and the form has no field dependencies to encode', function () {
    $resource = new ContentResource;

    foreach ($resource->fields() as $field) {
        $schema = $field->toSchema();
        foreach (['visible', 'required_when'] as $key) {
            if (isset($schema[$key])) {
                Condition::validate($schema[$key]);
            }
        }
        expect($schema)->not->toHaveKey('visible')->not->toHaveKey('required_when');
    }
    foreach ($resource->actions() as $action) {
        if ($action->getVisibleWhen() !== null) {
            Condition::validate($action->getVisibleWhen());
        }
    }
});

it('offers every content status as a status field value with a label key', function () {
    $schema = (new SchemaBuilder)->build(new ContentResource, contentResourceUser(1));
    $columns = array_column($schema['list']['columns'], null, 'field');

    expect(array_column($columns['status']['options'], 'value'))->toBe(array_column(ContentStatusEnum::cases(), 'value'));
    expect(array_column($columns['status']['options'], 'label'))->toContain('text.content.status_content_published');
});

it('has English and Vietnamese text for every label key the resource uses', function () {
    $sidecar = collect(require module_path('Content', 'database/seeders/sql/translate-admin-authored.php'))->keyBy('name');
    $resource = new ContentResource;
    $source = file_get_contents((new ReflectionClass($resource))->getFileName());
    preg_match_all("/self::L\.'([a-z_]+)'/", $source, $matches);
    $keys = collect($matches[1])->unique()->reject(fn ($k) => $k === 'status_')->map(fn ($k) => 'text.content.'.$k)->all();
    foreach (ContentStatusEnum::cases() as $status) {
        $keys[] = 'text.content.status_'.$status->value;
    }

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($sidecar->has($key))->toBeTrue("missing sidecar key {$key}");
        expect(trim($sidecar[$key]['text']['en']))->not->toBe('');
        expect(trim($sidecar[$key]['text']['vi']))->not->toBe('');
    }
});
