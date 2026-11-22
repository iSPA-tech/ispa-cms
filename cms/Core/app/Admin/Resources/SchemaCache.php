<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use App\Models\User;
use Closure;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\DataScope\DataScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Cache of built admin resource schemas.
 *
 * Custom fields and overlays made the schema read the database, so the final schema (after the permission stage) is
 * kept for one hour in the default cache store (never `redis_queue`). A hit needs no further database read for the
 * definition, custom fields or overlay.
 *
 * Key: `resource-schema:{module}:{resource}:{definition}:{permissions}:{locale}:{version}:{custom-epoch}:{overlay-epoch}`
 *
 * - definition: sha1 of the resource class, its file mtime and a serialization of what the code declares (fields with
 *   their abilities, list, form, detail and actions). A deploy or a definition that varies at runtime gives a new key.
 * - permissions: sha1 of every ability the schema depends on, evaluated for this user on every request
 *   (`ability => allowed`, sorted): the resource ability and view/manage abilities, each declared field's
 *   permission ability, the form ability, each action ability and each action input field's ability. Plus the sorted
 *   field names `DataScope::hiddenFields()` hides when the resource names a scoped resource (computed exactly like
 *   the permission stage), and whether the user is a Cms user. Two users with the same evaluated rights share one
 *   entry; a user with other rights can never get another user's entry.
 * - locale, schema version ({@see SchemaBuilder::VERSION}).
 * - custom / overlay epochs: random tokens under `resource-schema-epoch:{custom|overlay}:<resource key>` in the same
 *   store, replaced by {@see bumpCustomFields()} (CustomFieldService writes) and {@see bumpOverlay()} (ResourceOverlay
 *   saved/deleted). A missing token is created on read, so a token lost to eviction gives a cold cache, never a stale
 *   one.
 *
 * The key holds no user id, e-mail or secret.
 *
 * Why no permission-change listener is needed: permissions change through `RoleController::syncPermissions` /
 * `update` (`$model->permissions()->sync(...)`, RoleController.php:262 and :342), `UserController` role sync
 * (`$model->roles()->sync(...)`, UserController.php:574) and the config package import (`RolesSection`,
 * `syncWithoutDetaching`, RolesSection.php:144 and :339); `grep -rn "permission_role\|syncPermissions" cms/Core/app`
 * finds only the table constant and `RoleController::syncPermissions`. None of them needs to notify this cache,
 * because the permission hash is recomputed from Gate decisions on every request: after any of those writes the same
 * user evaluates to a different ability map and therefore a different key.
 *
 * Custom field abilities: a custom field cannot carry a permission ability today (`CustomFieldMapper` never calls
 * `Field::permission()`, and an overlay can only hide, order and relabel). Rather than trusting that, the builder
 * passes a `$storable` check that refuses to store a schema whose built fields or action inputs carry an ability that
 * is not part of the declared ability set covered by the key; such a schema is returned uncached. Correctness wins
 * over saving the query.
 *
 * Store failures: any exception from the cache store makes the schema build without the cache; the failure is logged
 * once per request (a container flag, so every request and test starts fresh). Bumps never throw either.
 */
final class SchemaCache
{
    /** Seconds a built schema stays cached. */
    public const TTL = 3600;

    /** Container flag set once a store failure was logged in this request. */
    private const FAILURE_LOGGED = 'resource-schema-cache.failure-logged';

    /**
     * Create the cache.
     *
     * @param  int  $version  schema format version that is part of every key
     */
    public function __construct(private readonly int $version = SchemaBuilder::VERSION) {}

    /**
     * Return the cached schema of a resource for a user, building and storing it on a miss.
     *
     * @param  Closure(): array<string, mixed>  $build  builds the final schema (after the permission stage)
     * @param  (Closure(): bool)|null  $storable  called after a build; false keeps the schema out of the cache
     * @return array<string, mixed>
     */
    public function remember(Resource $resource, Authenticatable $user, string $locale, Closure $build, ?Closure $storable = null): array
    {
        try {
            $key = $this->key($resource, $user, $locale);
            $hit = $this->store()->get($key);
        } catch (Throwable $e) {
            $this->failed($e, $resource->key());

            return $build();
        }

        if (is_array($hit)) {
            return $hit;
        }

        $schema = $build();
        if ($storable !== null && ! $storable()) {
            return $schema;
        }

        try {
            $this->store()->put($key, $schema, self::TTL);
        } catch (Throwable $e) {
            $this->failed($e, $resource->key());
        }

        return $schema;
    }

    /**
     * Cache key of a resource schema for a user and locale (reads or creates the epoch tokens).
     */
    public function key(Resource $resource, Authenticatable $user, string $locale): string
    {
        $resourceKey = $resource->key();
        [$module, $name] = array_pad(explode('/', $resourceKey, 2), 2, '_');

        return implode(':', [
            'resource-schema',
            $this->segment($module),
            $this->segment($name),
            $this->definitionHash($resource),
            $this->permissionHash($resource, $user),
            $this->segment($locale),
            $this->version,
            $this->epoch('custom', $resourceKey),
            $this->epoch('overlay', $resourceKey),
        ]);
    }

    /**
     * Every ability the cache key covers for a resource: resource, view/manage, field, form, action and action input
     * abilities, as declared in code.
     *
     * @return array<int, string>
     */
    public function declaredAbilities(Resource $resource): array
    {
        $abilities = [$resource->ability()];
        foreach ($resource->abilities() ?? [] as $ability) {
            $abilities[] = $ability;
        }
        foreach ($resource->fields() as $field) {
            $abilities[] = $field->permissionAbility();
        }
        $abilities[] = $resource->form()?->getAbility();
        foreach ($resource->actions() as $action) {
            $abilities[] = $action->getAbility();
            foreach ($action->getFields() as $field) {
                $abilities[] = $field->permissionAbility();
            }
        }

        $abilities = array_values(array_unique(array_filter($abilities, static fn ($a): bool => is_string($a) && $a !== '')));
        sort($abilities);

        return $abilities;
    }

    /** Invalidate the cached schemas of a resource after a custom field change. Never throws. */
    public function bumpCustomFields(string $resourceKey): void
    {
        $this->bump('custom', $resourceKey);
    }

    /** Invalidate the cached schemas of a resource after an overlay change. Never throws. */
    public function bumpOverlay(string $resourceKey): void
    {
        $this->bump('overlay', $resourceKey);
    }

    /** sha1 of the code definition of the resource. */
    private function definitionHash(Resource $resource): string
    {
        $file = (new ReflectionClass($resource))->getFileName();
        $mtime = is_string($file) ? (string) @filemtime($file) : '';

        try {
            $declared = serialize([
                $resource->fields(),
                $resource->list(),
                $resource->form(),
                $resource->detail(),
                $resource->actions(),
                $resource->abilities(),
                $resource->scopedResource(),
                $resource->supportsCustomFields(),
            ]);
        } catch (Throwable) {
            // Closures in a definition cannot be serialized: fall back to the exported field schema.
            $declared = json_encode(array_map(
                static fn (Field $field): array => [$field->name(), $field->permissionAbility(), $field->toSchema()],
                $resource->fields(),
            ));
        }

        return sha1($resource::class.'|'.$mtime.'|'.$declared);
    }

    /** sha1 of the user's evaluated rights for everything the schema depends on. */
    private function permissionHash(Resource $resource, Authenticatable $user): string
    {
        $gate = Gate::forUser($user);
        $evaluated = [];
        foreach ($this->declaredAbilities($resource) as $ability) {
            $evaluated[$ability] = $gate->allows($ability);
        }
        ksort($evaluated);

        $hidden = [];
        $scoped = $resource->scopedResource();
        if ($scoped !== null) {
            // Same computation as PermissionStage::hiddenFieldNames().
            $hidden = app(DataScope::class)->hiddenFields($scoped, $user instanceof User ? $user : null);
            $hidden = array_values(array_unique($hidden));
            sort($hidden);
        }

        return sha1(json_encode([
            'abilities' => $evaluated,
            'hidden' => $hidden,
            'cms_user' => $user instanceof User,
        ], JSON_THROW_ON_ERROR));
    }

    /** Current epoch token of a resource; a missing token is created now. */
    private function epoch(string $kind, string $resourceKey): string
    {
        $key = $this->epochKey($kind, $resourceKey);
        $token = $this->store()->get($key);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = Str::random(16);
        $this->store()->forever($key, $token);

        return $token;
    }

    /** Store a fresh epoch token, logging instead of throwing on a store failure. */
    private function bump(string $kind, string $resourceKey): void
    {
        try {
            $this->store()->forever($this->epochKey($kind, $resourceKey), Str::random(16));
        } catch (Throwable $e) {
            $this->failed($e, $resourceKey);
        }
    }

    /** Cache key of an epoch token. */
    private function epochKey(string $kind, string $resourceKey): string
    {
        return "resource-schema-epoch:{$kind}:{$resourceKey}";
    }

    /** A key segment without the separator or whitespace. */
    private function segment(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', $value);
    }

    /** The default cache store. */
    private function store(): Repository
    {
        return Cache::store();
    }

    /** Log a store failure once per request. */
    private function failed(Throwable $e, string $resourceKey): void
    {
        if (app()->bound(self::FAILURE_LOGGED)) {
            return;
        }
        app()->instance(self::FAILURE_LOGGED, true);

        try {
            CMSLogger::warning([
                'message' => 'Resource schema cache unavailable; schema built without cache',
                'resource' => $resourceKey,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never break the schema endpoint.
        }
    }
}
