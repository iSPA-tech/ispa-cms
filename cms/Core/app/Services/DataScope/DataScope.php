<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;
use JsonSerializable;
use UnexpectedValueException;

/**
 * The data-scope query layer: the only place that narrows a resource query to the rows an actor may see.
 *
 * Permission first: the Gate decides whether the actor holds the permission; this service only narrows rows.
 * The filter is registered as an instance global scope (`SCOPE_ID`), so Eloquent applies it at execution time and
 * nests the caller's own where clauses in their own group: a caller `orWhere` cannot widen the scope, whether it was
 * added before or after apply(). Visible dimension nodes come from the ExpansionPlan computed by ScopeResolver and
 * the dimension's HierarchyProvider; this service never computes anchors.
 *
 * Fail closed: no actor, an empty scope, a dimension the resource does not map, a dimension without a live provider
 * and a predicate that adds no constraint contribute nothing; when nothing contributes the query returns no rows.
 * Bound as a container `scoped()` instance, so resolved scopes are memoised per request or job only.
 */
class DataScope
{
    /**
     * Identifier of the instance global scope added by apply().
     */
    public const SCOPE_ID = 'data_scope';

    /**
     * Resolved scopes keyed by "<resource key>|<user id>".
     *
     * @var array<string, ResolvedScope>
     */
    protected array $scopes = [];

    /**
     * @param ScopedResourceRegistry $resources Registered scoped resources.
     * @param ScopeResolver $resolver Resolves user × permission key to a ResolvedScope.
     * @param DimensionRegistry $dimensions Registered dimensions and their hierarchy providers.
     */
    public function __construct(
        protected ScopedResourceRegistry $resources,
        protected ScopeResolver $resolver,
        protected DimensionRegistry $dimensions,
    ) {
    }

    /**
     * Narrow the query to the rows of the resource the actor may see.
     *
     * Scope `all` leaves the query unchanged; a null actor or an empty scope matches no rows; otherwise one group
     * ORs the own predicate (when the scope has `own` and the resource defines one) with each dimension predicate
     * the resource maps and the scope plans.
     *
     * @throws InvalidArgumentException When the resource is unknown or the query is not over the resource model.
     */
    public function apply(Builder $query, string $resource, ?User $actor): Builder
    {
        $definition = $this->resources->get($resource);

        $model = $definition->model();

        if (! $query->getModel() instanceof $model) {
            throw new InvalidArgumentException("Query model does not match scoped resource [{$resource}].");
        }

        $scope = $this->scopeFor($resource, $actor);

        if ($scope->all) {
            return $query;
        }

        $branches = $actor === null ? [] : $this->branches($query->getModel(), $definition, $scope, $actor);

        return $query->withGlobalScope(self::SCOPE_ID, static function (Builder $builder) use ($branches): void {
            if ($branches === []) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->getQuery()->where(static function (QueryBuilder $group) use ($branches): void {
                foreach ($branches as $branch) {
                    $group->addNestedWhereQuery($branch, 'or');
                }
            });
        });
    }

    /**
     * Whether the actor may see the record, by re-querying its key through apply().
     */
    public function allows(string $resource, Model $record, ?User $actor): bool
    {
        $model = $this->resources->get($resource)->model();

        if (! $record instanceof $model || $record->getKey() === null) {
            return false;
        }

        return $this->apply($model::query(), $resource, $actor)->whereKey($record->getKey())->exists();
    }

    /**
     * Find a record of the resource by uuid within the actor's scope.
     *
     * An out-of-scope record throws exactly the same ModelNotFoundException as an unknown uuid.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $resource, string $uuid, ?User $actor): Model
    {
        $definition = $this->resources->get($resource);
        $model = $definition->model();
        $query = $model::query();

        return $this->apply($query, $resource, $actor)
            ->where($query->qualifyColumn($definition->getUuidColumn()), $uuid)
            ->firstOrFail();
    }

    /**
     * Whether the actor may reach a polymorphic target.
     *
     * True when no scoped resource is registered for the class (or the class is null); otherwise true only when the
     * record exists and is in scope (a null id is false). A read checks only the first resource registered for the
     * class. A write ($write = true) must pass the scope of every resource registered for the class, so a user who
     * may view a record through a wide scope cannot change it outside a narrower manage scope.
     */
    public function allowsTarget(?string $modelClass, int|string|null $id, ?User $actor, bool $write = false): bool
    {
        if ($modelClass === null) {
            return true;
        }

        $definitions = $write
            ? $this->resources->allForModel($modelClass)
            : array_filter([$this->resources->forModel($modelClass)]);

        if ($definitions === []) {
            return true;
        }

        if ($id === null || $id === '') {
            return false;
        }

        foreach ($definitions as $definition) {
            $model = $definition->model();

            if (! $this->apply($model::query(), $definition->key(), $actor)->whereKey($id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The scope the actor holds for the resource (memoised per instance).
     *
     * A null actor sees nothing; an actor whose scope for one of the resource's unrestrictedWith abilities is `all`
     * sees everything; otherwise the scope of the resource permission.
     *
     * @throws InvalidArgumentException When the resource is unknown.
     */
    public function scopeFor(string $resource, ?User $actor): ResolvedScope
    {
        $definition = $this->resources->get($resource);

        if ($actor === null) {
            return ResolvedScope::none();
        }

        $memoKey = $resource . '|' . ($actor->getKey() ?? 'new#' . spl_object_id($actor));

        return $this->scopes[$memoKey] ??= $this->resolve($definition, $actor);
    }

    /**
     * Marked field paths of the resource the actor may not see, in declaration order (hideUnless, then hideUnlessAll).
     *
     * A null actor may see no marked field. A hideUnless field is visible when the actor is a Webmaster or holds
     * every ability of the mark (`User::hasPermissionResolved`, the Gate rule for dotted keys); a hideUnlessAll
     * field is visible when scopeFor() is `all`.
     *
     * @return array<int, string>
     * @throws InvalidArgumentException When the resource is unknown.
     */
    public function hiddenFields(string $resource, ?User $actor): array
    {
        $definition = $this->resources->get($resource);
        $hidden = [];

        foreach ($definition->hiddenUnlessFields() as $field => $abilities) {
            if ($actor === null || ! $this->holdsAll($actor, $abilities)) {
                $hidden[] = $field;
            }
        }

        $allFields = $definition->hiddenUnlessAllFields();

        if ($allFields !== [] && ($actor === null || ! $this->scopeFor($resource, $actor)->all)) {
            foreach ($allFields as $field) {
                if (! in_array($field, $hidden, true)) {
                    $hidden[] = $field;
                }
            }
        }

        return $hidden;
    }

    /**
     * Remove the marked fields the actor may not see from one presenter row.
     *
     * The row may be a plain array or any value convertible to one (Eloquent model, JsonResource, Arrayable,
     * JsonSerializable). Only the containers on a hidden path are converted; every other value keeps its type.
     * Keys are unset (never nulled); missing keys are not created; everything else keeps its order and type.
     * Wildcards `*` walk list and associative arrays alike; scalars and null on a path have nothing to remove.
     *
     * @param array<mixed>|Arrayable<array-key, mixed>|JsonSerializable|JsonResource|Model $row
     * @return array<mixed>
     * @throws InvalidArgumentException When the resource is unknown.
     * @throws UnexpectedValueException When the row, or a value a hidden path must traverse, cannot become an array.
     */
    public function strip(mixed $row, string $resource, ?User $actor): array
    {
        return $this->stripWith($row, $this->hiddenFields($resource, $actor));
    }

    /**
     * Remove the marked fields the actor may not see from every row (hidden fields are computed once).
     *
     * Accepts arrays and any iterable (e.g. an Eloquent collection); keys are preserved.
     *
     * @param iterable<array-key, mixed> $rows
     * @return array<array-key, array<mixed>>
     * @throws InvalidArgumentException When the resource is unknown.
     * @throws UnexpectedValueException When a row, or a value a hidden path must traverse, cannot become an array.
     */
    public function stripMany(iterable $rows, string $resource, ?User $actor): array
    {
        $hidden = $this->hiddenFields($resource, $actor);
        $stripped = [];

        foreach ($rows as $index => $row) {
            $stripped[$index] = $this->stripWith($row, $hidden);
        }

        return $stripped;
    }

    /**
     * Strip the given hidden paths from one row, normalising it to an array first when it is not one.
     *
     * @param array<int, string> $hidden
     * @return array<mixed>
     * @throws UnexpectedValueException When the row cannot become an array.
     */
    protected function stripWith(mixed $row, array $hidden): array
    {
        if ($hidden === [] && is_array($row)) {
            return $row;
        }

        $data = $this->normalise($row);

        if (! is_array($data)) {
            throw new UnexpectedValueException('A data-scope row must be an array or convertible to one, got ' . get_debug_type($row) . '.');
        }

        return $this->forgetAll($data, $hidden);
    }

    /**
     * Convert a value to an array when it is a JsonResource, Arrayable or JsonSerializable (repeatedly).
     *
     * Arrays, scalars and null are returned as they are; any other object is returned as is for the caller to reject.
     */
    protected function normalise(mixed $value): mixed
    {
        for ($guard = 0; $guard < 16; $guard++) {
            if ($value instanceof JsonResource) {
                $value = $value->resolve();
            } elseif ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            } else {
                return $value;
            }
        }

        return $value;
    }

    /**
     * Whether the actor is a Webmaster or holds every ability.
     *
     * @param array<int, string> $abilities
     */
    protected function holdsAll(User $actor, array $abilities): bool
    {
        $actor->loadMissing('roles.permissions');

        if ($actor->isWebmaster()) {
            return true;
        }

        foreach ($abilities as $ability) {
            if (! $actor->hasPermissionResolved($ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unset every dotted path from the data.
     *
     * @param array<mixed> $data
     * @param array<int, string> $paths
     * @return array<mixed>
     */
    protected function forgetAll(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $data = $this->forgetPath($data, explode('.', $path));
        }

        return $data;
    }

    /**
     * Unset one path (segments, `*` = every key) from the data without creating missing keys.
     *
     * @param array<mixed> $data
     * @param array<int, string> $segments
     * @return array<mixed>
     */
    protected function forgetPath(array $data, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segments === []) {
            if ($segment === '*') {
                return [];
            }

            unset($data[$segment]);

            return $data;
        }

        $keys = $segment === '*' ? array_keys($data) : (array_key_exists($segment, $data) ? [$segment] : []);

        foreach ($keys as $key) {
            $child = $data[$key];

            if (is_object($child)) {
                $child = $this->normalise($child);

                if (! is_array($child)) {
                    throw new UnexpectedValueException('A hidden field path runs through a value that cannot be converted to an array.');
                }
            }

            if (is_array($child)) {
                $data[$key] = $this->forgetPath($child, $segments);
            }
        }

        return $data;
    }

    /**
     * Compute the actor's scope for the resource without memoisation.
     */
    protected function resolve(ScopedResource $definition, User $actor): ResolvedScope
    {
        foreach ($definition->unrestrictedAbilities() as $ability) {
            if ($this->resolver->for($actor, $ability)->all) {
                return ResolvedScope::everything();
            }
        }

        return $this->resolver->for($actor, $definition->permission());
    }

    /**
     * Base where-queries of every contributing branch (own, then each mapped and planned dimension by name).
     *
     * @return array<int, QueryBuilder>
     */
    protected function branches(Model $model, ScopedResource $definition, ResolvedScope $scope, User $actor): array
    {
        $branches = [];
        $own = $definition->ownPredicate();

        if ($scope->own && $own !== null) {
            $branches[] = $this->branch($model, static fn (Builder $q) => $own($q, $actor));
        }

        foreach ($definition->dimensions() as $name => $apply) {
            $plan = $scope->plans[$name] ?? null;

            if ($plan === null || ($plan->selfNodeIds === [] && $plan->anchorIds === [])) {
                continue;
            }

            $provider = $this->dimensions->provider($name);

            if ($provider === null) {
                continue;
            }

            $visibleNodeIds = $plan->visibleIdsQuery($provider);
            $branches[] = $this->branch($model, static fn (Builder $q) => $apply($q, $visibleNodeIds));
        }

        return array_values(array_filter($branches));
    }

    /**
     * Run one predicate on a fresh query of the model and return its base query, or null when it added no constraint.
     */
    protected function branch(Model $model, Closure $predicate): ?QueryBuilder
    {
        $query = $model->newModelQuery();
        $predicate($query);
        $base = $query->getQuery();

        return empty($base->wheres) ? null : $base;
    }
}
