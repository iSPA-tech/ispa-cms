<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use Cms\Core\Admin\Resources\Pipeline\CustomFieldsStage;
use Cms\Core\Admin\Resources\Pipeline\DefinitionStage;
use Cms\Core\Admin\Resources\Pipeline\OverlayStage;
use Cms\Core\Admin\Resources\Pipeline\PermissionStage;
use Cms\Core\Admin\Resources\Pipeline\SchemaContext;
use Cms\Core\Admin\Resources\Pipeline\SchemaStage;
use Cms\Core\Records\RecordRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Builds the per-user UI schema of a {@see Resource}.
 *
 * Endpoint paths: the base-admin `@/ajax` client (`base-admin/src/ajax.js`)
 * uses `baseURL: apiBase`, and `base-admin/src/config/base.js` defines
 * `apiBase = url + 'api/v2/admin/'`. Endpoint `path` values are therefore the
 * route URI with exactly the leading `/api/v2/admin/` removed, with `:param`
 * placeholders for every route parameter (for example
 * `helpdesk/organizations/:uuid`). A route outside that prefix is a definition
 * error and throws.
 *
 * The schema is built by an ordered pipeline of {@see SchemaStage}s (spec 6.6): definition, custom fields, per-site
 * overlay, then removal of what the user may not use. Endpoint building and validation live in {@see DefinitionStage},
 * permission and data-scope removal in {@see PermissionStage}.
 *
 * Output keys: `schema_version, resource, list, form, detail, actions` (plus `abilities` when the resource declares
 * {@see Resource::abilities()}). A view
 * the resource does not declare (or the user may not use) is `null`; actions
 * default to an empty list. Fields, filters, columns, sections and actions the
 * user lacks the ability for are removed, and every condition is validated.
 *
 * When the resource names a {@see Resource::scopedResource()}, top-level fields whose name equals a path hidden by
 * `DataScope::hiddenFields()` for the user are removed too (nested hidden paths such as `a.b` hide only part of a
 * field and are left to the data response). A user that is not a Cms user counts as no actor (all marked fields hidden).
 */
final class SchemaBuilder
{
    /** Schema format version. */
    public const VERSION = 1;

    /** @var array<int, SchemaStage> */
    private readonly array $stages;

    /** Whether the standard pipeline runs, which is the only case served through {@see SchemaCache}. */
    private readonly bool $standard;

    /**
     * Create the builder.
     *
     * @param  array<int, SchemaStage>|null  $stages  stages in run order; null for the standard pipeline
     */
    public function __construct(?array $stages = null)
    {
        $this->standard = $stages === null;
        $this->stages = $stages ?? [
            new DefinitionStage,
            new CustomFieldsStage,
            new OverlayStage,
            new PermissionStage,
        ];
    }

    /**
     * Build the schema of a resource for a user.
     *
     * With the standard pipeline the final schema is served through {@see SchemaCache} (resolved from the container);
     * a builder with injected stages always builds, so stage spies stay deterministic.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException on an unknown route, unknown field or invalid condition
     * @throws LogicException when a route is outside the admin API prefix or an endpoint is missing
     */
    public function build(Resource $resource, Authenticatable $user): array
    {
        $locale = app()->getLocale();
        $context = new SchemaContext($resource, $user, $locale);

        if (! $this->standard) {
            return $this->withTimeline($this->run($context), $resource, $user);
        }

        $cache = app(SchemaCache::class);

        return $this->withTimeline($cache->remember(
            $resource,
            $user,
            $locale,
            fn (): array => $this->run($context),
            fn (): bool => $this->coveredByKey($context, $cache->declaredAbilities($resource)),
        ), $resource, $user);
    }

    /**
     * Add `detail.timeline = { record_type }` when the resource declares a timeline, the record type is registered and
     * the user holds its view ability. It runs after the cache because the cache key does not evaluate this ability.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withTimeline(array $schema, Resource $resource, Authenticatable $user): array
    {
        $key = $resource->detail()?->getTimelineType();
        if ($key === null || ! is_array($schema['detail'] ?? null)) {
            return $schema;
        }

        $type = app(RecordRegistry::class)->get($key);
        if ($type !== null && Gate::forUser($user)->allows($type->viewAbility)) {
            $schema['detail']['timeline'] = ['record_type' => $key];
        }

        return $schema;
    }

    /**
     * Run every stage in order.
     *
     * @return array<string, mixed>
     */
    private function run(SchemaContext $context): array
    {
        $schema = [];
        foreach ($this->stages as $stage) {
            $schema = $stage->apply($schema, $context);
        }

        return $schema;
    }

    /**
     * Whether every ability of the built fields and action inputs is one the cache key evaluates. A field added at
     * build time (custom field, overlay) with another ability would make the key blind to it, so it is not stored.
     *
     * @param  array<int, string>  $declared
     */
    private function coveredByKey(SchemaContext $context, array $declared): bool
    {
        $fields = array_values($context->fields());
        foreach ($context->resource->actions() as $action) {
            array_push($fields, ...$context->actionInputs($action->name()));
        }
        foreach ($fields as $field) {
            $ability = $field->permissionAbility();
            if ($ability !== null && ! in_array($ability, $declared, true)) {
                return false;
            }
        }

        return true;
    }
}
