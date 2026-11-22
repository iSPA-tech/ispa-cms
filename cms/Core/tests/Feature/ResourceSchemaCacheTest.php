<?php

use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Cms\Core\Admin\Resources\SchemaCache;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Models\ResourceOverlay;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** A saved backup with one stored copy, the proof a purge needs. */
function cacheStoredBackup(): Backup
{
    $destination = BackupDestination::query()->firstOrCreate(['name' => 'cache-test-local'], ['driver' => 'local', 'config' => ['disk' => 'local']]);
    $backup = Backup::query()->create([
        'reason' => Backup::REASON_PRE_PURGE, 'status' => Backup::STATUS_SUCCEEDED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz',
    ]);
    BackupCopy::query()->create([
        'backup_id' => $backup->id, 'destination_id' => $destination->id, 'path' => 'cache-test/backup.sqlite.gz', 'status' => BackupCopy::STATUS_STORED,
    ]);

    return $backup;
}

/** Resource used by the schema cache tests: custom fields on, view/manage split and one restricted field. */
class SchemaCacheTestResource extends Resource
{
    /** Resource key. */
    public function key(): string
    {
        return 'cachetest/items';
    }

    /** View ability. */
    public function ability(): string
    {
        return 'cachetest.items.view';
    }

    /** View and manage abilities. */
    public function abilities(): ?array
    {
        return ['view' => 'cachetest.items.view', 'manage' => 'cachetest.items.manage'];
    }

    /** Opt in to custom fields. */
    public function supportsCustomFields(): bool
    {
        return true;
    }

    /** Resource fields. */
    public function fields(): array
    {
        return [
            Field::text('name')->required()->max(191),
            Field::text('code'),
            Field::text('secret')->permission('cachetest.items.secret'),
        ];
    }

    /** List view. */
    public function list(): ?ListView
    {
        return ListView::make()->columns(['name', 'code', 'secret'])->endpoint('cachetest.index');
    }

    /** Form view. */
    public function form(): ?FormView
    {
        return FormView::create(['name', 'code', 'secret'])->endpoint('cachetest.store')
            ->update(['name', 'code', 'secret'])->updateEndpoint('cachetest.update');
    }

    /** Detail view. */
    public function detail(): ?DetailView
    {
        return DetailView::make()->endpoint('cachetest.show')->sections(['name', 'code', 'secret']);
    }
}

/** A non-Cms user with the given id; abilities come from the Gate definitions below. */
function cacheUser(int $id): AuthUser
{
    return tap(new AuthUser, fn ($u) => $u->id = $id);
}

/** Build the schema through the standard (cached) pipeline. */
function cacheBuild(AuthUser $user): array
{
    return (new SchemaBuilder)->build(new SchemaCacheTestResource, $user);
}

/** Field names of the update form, or null when the form is absent. */
function cacheFormFields(array $schema): ?array
{
    return is_array($schema['form'] ?? null) ? array_column($schema['form']['update']['fields'], 'field') : null;
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();
    $p = 'api/v2/admin/';
    Route::get($p.'cachetest', fn () => null)->name('cachetest.index');
    Route::post($p.'cachetest', fn () => null)->name('cachetest.store');
    Route::get($p.'cachetest/{uuid}', fn () => null)->name('cachetest.show');
    Route::put($p.'cachetest/{uuid}', fn () => null)->name('cachetest.update');
    app('router')->getRoutes()->refreshNameLookups();
    app(ResourceRegistry::class)->register('cachetest/items', SchemaCacheTestResource::class);

    // Users 1 and 2 are admins (same permission set); user 3 may only view.
    Gate::define('cachetest.items.view', fn ($user) => true);
    Gate::define('cachetest.items.manage', fn ($user) => in_array($user->id, [1, 2], true));
    Gate::define('cachetest.items.secret', fn ($user) => in_array($user->id, [1, 2], true));
});

it('serves the second call from the cache without building again', function () {
    $cache = app(SchemaCache::class);
    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return ['built' => $calls];
    };

    $first = $cache->remember(new SchemaCacheTestResource, cacheUser(1), 'vi', $build);
    $second = $cache->remember(new SchemaCacheTestResource, cacheUser(1), 'vi', $build);

    expect($calls)->toBe(1)->and($second)->toBe($first);
});

it('caches the final schema so a hit equals a fresh build', function () {
    $fresh = (new SchemaBuilder([
        new Cms\Core\Admin\Resources\Pipeline\DefinitionStage,
        new Cms\Core\Admin\Resources\Pipeline\CustomFieldsStage,
        new Cms\Core\Admin\Resources\Pipeline\OverlayStage,
        new Cms\Core\Admin\Resources\Pipeline\PermissionStage,
    ]))->build(new SchemaCacheTestResource, cacheUser(1));

    expect(cacheBuild(cacheUser(1)))->toBe($fresh)
        ->and(cacheBuild(cacheUser(1)))->toBe($fresh);
});

it('shows a custom field change on the next call', function () {
    $service = app(CustomFieldService::class);
    $user = cacheUser(1);
    expect(cacheFormFields(cacheBuild($user)))->toBe(['name', 'code', 'secret']);

    $field = $service->create('cachetest/items', ['key' => 'industry', 'type' => 'text', 'labels' => ['vi' => 'Ngành', 'en' => 'Industry']], null);
    expect(cacheFormFields(cacheBuild($user)))->toContain('custom_fields.industry');

    $service->update($field, ['labels' => ['vi' => 'Lĩnh vực', 'en' => 'Sector']]);
    $entry = collect(cacheBuild($user)['form']['update']['fields'])->firstWhere('field', 'custom_fields.industry');
    expect(json_encode($entry, JSON_UNESCAPED_UNICODE))->toContain('Sector');

    $second = $service->create('cachetest/items', ['key' => 'region', 'type' => 'text', 'labels' => ['vi' => 'Vùng', 'en' => 'Region']], null);
    $tail = fn () => array_slice(cacheFormFields(cacheBuild($user)), -2);
    expect($tail())->toBe(['custom_fields.industry', 'custom_fields.region']);

    $service->reorder('cachetest/items', [$second->uuid, $field->uuid]);
    expect($tail())->toBe(['custom_fields.region', 'custom_fields.industry']);

    $service->archive($field->fresh());
    expect(cacheFormFields(cacheBuild($user)))->not->toContain('custom_fields.industry');

    $service->restore($field->fresh());
    expect(cacheFormFields(cacheBuild($user)))->toContain('custom_fields.industry');

    $service->purge($field->fresh(), 'industry', cacheStoredBackup());
    expect(cacheFormFields(cacheBuild($user)))->not->toContain('custom_fields.industry');
});

it('shows an overlay save and delete on the next call', function () {
    $user = cacheUser(1);
    expect(cacheFormFields(cacheBuild($user)))->toContain('code');

    $overlay = ResourceOverlay::create(['resource_key' => 'cachetest/items', 'hidden' => ['code']]);
    expect(cacheFormFields(cacheBuild($user)))->not->toContain('code');

    $overlay->update(['hidden' => ['secret']]);
    expect(cacheFormFields(cacheBuild($user)))->toContain('code')->not->toContain('secret');

    $overlay->delete();
    expect(cacheFormFields(cacheBuild($user)))->toContain('code')->toContain('secret');
});

it('never serves an admin schema to a user with fewer permissions', function () {
    $admin = cacheBuild(cacheUser(1));
    expect($admin['form'])->not->toBeNull()
        ->and(array_column($admin['list']['columns'], 'field'))->toContain('secret');

    $cache = app(SchemaCache::class);
    $resource = new SchemaCacheTestResource;
    expect($cache->key($resource, cacheUser(3), 'vi'))->not->toBe($cache->key($resource, cacheUser(1), 'vi'));

    $viewer = cacheBuild(cacheUser(3));
    expect($viewer['form'])->toBeNull()
        ->and($viewer['abilities'])->toBe(['view' => true, 'manage' => false])
        ->and(array_column($viewer['list']['columns'], 'field'))->not->toContain('secret');
});

it('changes the key as soon as a permission changes', function () {
    $cache = app(SchemaCache::class);
    $resource = new SchemaCacheTestResource;
    $before = $cache->key($resource, cacheUser(3), 'vi');
    expect(cacheBuild(cacheUser(3))['form'])->toBeNull();

    Gate::define('cachetest.items.manage', fn ($user) => true);

    expect($cache->key($resource, cacheUser(3), 'vi'))->not->toBe($before)
        ->and(cacheBuild(cacheUser(3))['form'])->not->toBeNull();
});

it('shares one entry between users with the same permission set', function () {
    $cache = app(SchemaCache::class);
    $resource = new SchemaCacheTestResource;
    expect($cache->key($resource, cacheUser(1), 'vi'))->toBe($cache->key($resource, cacheUser(2), 'vi'));

    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return ['schema'];
    };
    $cache->remember($resource, cacheUser(1), 'vi', $build);
    $cache->remember($resource, cacheUser(2), 'vi', $build);
    $cache->remember($resource, cacheUser(3), 'vi', $build);

    expect($calls)->toBe(2);
});

it('uses a different key per locale and per schema version', function () {
    $resource = new SchemaCacheTestResource;
    $cache = app(SchemaCache::class);

    expect($cache->key($resource, cacheUser(1), 'vi'))->not->toBe($cache->key($resource, cacheUser(1), 'en'))
        ->and((new SchemaCache(SchemaBuilder::VERSION + 1))->key($resource, cacheUser(1), 'vi'))
        ->not->toBe($cache->key($resource, cacheUser(1), 'vi'));
});

it('builds a key without user id, email or secret', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('0', 32))]); // obviously fake test key
    $user = tap(new AuthUser, function ($u) {
        $u->id = 987654;
        $u->email = 'someone@example.test';
    });

    $key = app(SchemaCache::class)->key(new SchemaCacheTestResource, $user, 'vi');

    expect($key)->toMatch('/^resource-schema:cachetest:items:[0-9a-f]{40}:[0-9a-f]{40}:vi:'.SchemaBuilder::VERSION.':[A-Za-z0-9]+:[A-Za-z0-9]+$/')
        ->not->toContain('987654')
        ->not->toContain('someone')
        ->not->toContain('MDAwMDAw');
});

it('still returns a built schema when the cache store throws, logging once', function () {
    $throwing = new class implements Store
    {
        public function get($key) { throw new RuntimeException('store down'); }

        public function many(array $keys) { throw new RuntimeException('store down'); }

        public function put($key, $value, $seconds) { throw new RuntimeException('store down'); }

        public function putMany(array $values, $seconds) { throw new RuntimeException('store down'); }

        public function increment($key, $value = 1) { throw new RuntimeException('store down'); }

        public function decrement($key, $value = 1) { throw new RuntimeException('store down'); }

        public function forever($key, $value) { throw new RuntimeException('store down'); }

        public function touch($key, $seconds) { throw new RuntimeException('store down'); }

        public function forget($key) { throw new RuntimeException('store down'); }

        public function flush() { throw new RuntimeException('store down'); }

        public function getPrefix() { return ''; }
    };
    Cache::extend('schema-cache-throwing', fn () => Cache::repository($throwing));
    config(['cache.stores.schema-cache-throwing' => ['driver' => 'schema-cache-throwing'], 'cache.default' => 'schema-cache-throwing']);
    CMSLogger::shouldReceive('warning')->once();

    $first = cacheBuild(cacheUser(1));
    $second = cacheBuild(cacheUser(1));

    expect(cacheFormFields($first))->toBe(['name', 'code', 'secret'])->and($second)->toBe($first);

    // A bump against the broken store does not throw out of the caller.
    app(SchemaCache::class)->bumpOverlay('cachetest/items');
});

it('does not store a schema the storable check refuses', function () {
    $cache = app(SchemaCache::class);
    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return ['schema'];
    };

    $cache->remember(new SchemaCacheTestResource, cacheUser(1), 'vi', $build, fn () => false);
    $cache->remember(new SchemaCacheTestResource, cacheUser(1), 'vi', $build, fn () => false);

    expect($calls)->toBe(2)
        ->and($cache->declaredAbilities(new SchemaCacheTestResource))
        ->toBe(['cachetest.items.manage', 'cachetest.items.secret', 'cachetest.items.view']);
});
