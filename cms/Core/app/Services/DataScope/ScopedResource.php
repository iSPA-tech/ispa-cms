<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Declaration of one data-scoped resource: which model it covers, which permission key decides its scope,
 * how rows owned by the actor are matched and how each dimension maps onto the model's columns.
 *
 * Built fluently, registered once in ScopedResourceRegistry, and only ever applied by DataScope.
 * Predicate closures must add where constraints only (no joins, selects or orders); a closure that adds no
 * constraint contributes nothing (fail closed).
 */
final class ScopedResource
{
    /**
     * Predicate matching rows owned by the actor: fn (Eloquent\Builder $query, User $actor): void.
     */
    protected ?Closure $own = null;

    /**
     * Predicate per dimension name: fn (Eloquent\Builder $query, Query\Builder $visibleNodeIds): void.
     *
     * @var array<string, Closure>
     */
    protected array $dimensions = [];

    /**
     * Permission keys whose `all` scope makes this resource unrestricted for the actor.
     *
     * @var array<int, string>
     */
    protected array $unrestrictedAbilities = [];

    /**
     * Column holding the public uuid used by DataScope::findOrFail().
     */
    protected string $uuidColumn = 'uuid';

    /**
     * Fields visible only to actors holding every listed ability: dotted path => abilities.
     *
     * @var array<string, array<int, string>>
     */
    protected array $hiddenUnless = [];

    /**
     * Fields visible only to actors whose scope for the resource is `all` (dotted paths).
     *
     * @var array<int, string>
     */
    protected array $hiddenUnlessAll = [];

    /**
     * @param string $key Resource key, e.g. `module/records`.
     * @param class-string<Model> $model Eloquent model class of the resource.
     * @param string $permission Permission key whose resolved scope narrows the rows.
     */
    private function __construct(
        protected string $key,
        protected string $model,
        protected string $permission,
    ) {
    }

    /**
     * Start a resource declaration.
     *
     * @param string $key Resource key, e.g. `module/records`.
     * @param class-string<Model> $model Eloquent model class of the resource.
     * @param string $permission Permission key whose resolved scope narrows the rows.
     * @throws InvalidArgumentException When the key or permission is empty or the class is not an Eloquent model.
     */
    public static function make(string $key, string $model, string $permission): self
    {
        if (trim($key) === '' || trim($permission) === '') {
            throw new InvalidArgumentException('A scoped resource needs a key and a permission key.');
        }

        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("Scoped resource [{$key}] model [{$model}] is not an Eloquent model.");
        }

        return new self($key, $model, $permission);
    }

    /**
     * Set the predicate that matches rows owned by the actor (used when the scope has `own`).
     *
     * @param Closure $apply fn (Eloquent\Builder $query, User $actor): void
     */
    public function own(Closure $apply): self
    {
        $this->own = $apply;

        return $this;
    }

    /**
     * Map a dimension onto the model: the closure constrains the query to rows whose node is in the subquery.
     *
     * @param string $name Dimension name as registered in DimensionRegistry.
     * @param Closure $apply fn (Eloquent\Builder $query, Query\Builder $visibleNodeIds): void
     */
    public function dimension(string $name, Closure $apply): self
    {
        $this->dimensions[$name] = $apply;

        return $this;
    }

    /**
     * Permission keys (abilities) whose `all` scope makes the actor see every row of this resource.
     */
    public function unrestrictedWith(string ...$abilities): self
    {
        $this->unrestrictedAbilities = array_values(array_unique(array_merge($this->unrestrictedAbilities, $abilities)));

        return $this;
    }

    /**
     * Set the column used to address a record by uuid (default `uuid`).
     */
    public function uuidColumn(string $column): self
    {
        $this->uuidColumn = $column;

        return $this;
    }

    /**
     * Mark a field visible only to actors holding the ability (DataScope::strip removes it for the others).
     *
     * @param string $field Dotted path with `*` wildcards (data_get semantics), e.g. `members.*.customer.email`.
     * @param string $ability Permission key the actor must hold.
     * @throws InvalidArgumentException When the path has an empty segment or the ability is empty.
     */
    public function hideUnless(string $field, string $ability): self
    {
        $field = self::validField($field);

        if (trim($ability) === '') {
            throw new InvalidArgumentException("Scoped resource [{$this->key}] field [{$field}] needs an ability.");
        }

        $abilities = $this->hiddenUnless[$field] ?? [];
        $abilities[] = $ability;
        $this->hiddenUnless[$field] = array_values(array_unique($abilities));

        return $this;
    }

    /**
     * Mark a field visible only to actors whose scope for the resource is `all` (DataScope::scopeFor semantics,
     * so an `all` scope on an unrestrictedWith ability counts).
     *
     * @param string $field Dotted path with `*` wildcards (data_get semantics).
     * @throws InvalidArgumentException When the path has an empty segment.
     */
    public function hideUnlessAll(string $field): self
    {
        $field = self::validField($field);

        if (! in_array($field, $this->hiddenUnlessAll, true)) {
            $this->hiddenUnlessAll[] = $field;
        }

        return $this;
    }

    /**
     * Fields marked with hideUnless(): dotted path => abilities the actor must all hold.
     *
     * @return array<string, array<int, string>>
     */
    public function hiddenUnlessFields(): array
    {
        return $this->hiddenUnless;
    }

    /**
     * Fields marked with hideUnlessAll() (dotted paths).
     *
     * @return array<int, string>
     */
    public function hiddenUnlessAllFields(): array
    {
        return $this->hiddenUnlessAll;
    }

    /**
     * Resource key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Eloquent model class of the resource.
     *
     * @return class-string<Model>
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * Permission key whose resolved scope narrows the rows.
     */
    public function permission(): string
    {
        return $this->permission;
    }

    /**
     * Own predicate, or null when the resource has no notion of ownership.
     */
    public function ownPredicate(): ?Closure
    {
        return $this->own;
    }

    /**
     * Dimension predicates keyed by dimension name.
     *
     * @return array<string, Closure>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Permission keys whose `all` scope makes this resource unrestricted.
     *
     * @return array<int, string>
     */
    public function unrestrictedAbilities(): array
    {
        return $this->unrestrictedAbilities;
    }

    /**
     * Column used to address a record by uuid.
     */
    public function getUuidColumn(): string
    {
        return $this->uuidColumn;
    }

    /**
     * Validate a dotted field path: not empty and no empty segment.
     *
     * @throws InvalidArgumentException
     */
    private static function validField(string $field): string
    {
        $field = trim($field);

        if ($field === '' || in_array('', explode('.', $field), true)) {
            throw new InvalidArgumentException("Invalid hidden field path [{$field}].");
        }

        return $field;
    }
}
