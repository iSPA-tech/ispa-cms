<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaValueBigNum;
use Cms\Core\Models\MetaValueBoolean;
use Cms\Core\Models\MetaValueDate;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\MetaValueText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Narrows a record list by the custom field values of its resource (spec 6.6,).
 *
 * Filters and search are `EXISTS` subqueries over `meta_data` joined to the typed value table; they never join the
 * list itself, so a record with several values is never duplicated. Every operator-supplied value is a bound
 * parameter, field keys only ever select from the resource's own field definitions, unknown and archived keys are
 * ignored. Core only: no module class is referenced.
 */
class CustomFieldQuery
{
    /** Escape character of `LIKE` patterns (a backslash escape is not portable to SQLite). */
    private const LIKE_ESCAPE = '!';

    /** Longest search text taken into account. */
    private const MAX_SEARCH_LENGTH = 100;

    /** Create the query helper; the field lookup is resolved from the container when not given. */
    public function __construct(private readonly ?CustomFieldService $fields = null) {}

    /**
     * Keep only the records that match every given custom field filter.
     *
     * Per field type: select and multiselect take one value or a list (a record matches when it has any of them),
     * boolean takes a boolean-like scalar, integer and date take a scalar (equality) or `{from?, to?}`. Text and
     * textarea fields are not filterable. Malformed values and unknown or archived keys are ignored.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters  field key => value
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyFilters(Builder $query, string $resourceKey, array $filters): Builder
    {
        if ($filters === []) {
            return $query;
        }

        foreach ($this->activeFields($resourceKey) as $field) {
            $key = $this->keyOf($field);
            if (! array_key_exists($key, $filters)) {
                continue;
            }
            $constrain = $this->valueConstraint($field, $filters[$key]);
            if ($constrain === null) {
                continue;
            }
            $query->whereExists($this->subquery($query, $field, $constrain));
        }

        return $query;
    }

    /**
     * Keep only the records whose searchable custom fields contain the text.
     *
     * Standalone form: the matches are one OR group, ANDed with the rest of the query.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applySearch(Builder $query, string $resourceKey, string $text): Builder
    {
        if ($this->searchableFields($resourceKey, $text) === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $group) => $this->orSearch($group, $resourceKey, $text));
    }

    /**
     * Add the custom field matches as `OR EXISTS` clauses to an existing OR group (for example the `q` group of a list).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $group  the group builder of a `where(function ($group) {...})`; the model must be the listed model
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function orSearch(Builder $group, string $resourceKey, string $text): Builder
    {
        $like = $this->likePattern($text);
        foreach ($this->searchableFields($resourceKey, $text) as $field) {
            $group->orWhereExists($this->subquery($group, $field, function (QueryBuilder $value) use ($like, $field): void {
                $value->whereRaw('lower('.$this->valueTable($field).'.value) like ? escape \''.self::LIKE_ESCAPE.'\'', [$like]);
            }));
        }

        return $group;
    }

    /**
     * `LIKE` pattern for a search text, escaping `%`, `_` and the escape character; same logic as the Helpdesk search.
     */
    public function likePattern(string $text): string
    {
        $text = mb_strtolower(trim(mb_substr($text, 0, self::MAX_SEARCH_LENGTH)));

        return '%'.str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $text).'%';
    }

    /**
     * Non-archived searchable text-like fields; empty for an empty text.
     *
     * @return list<MetaAttribute>
     */
    private function searchableFields(string $resourceKey, string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return $this->activeFields($resourceKey)
            ->filter(fn (MetaAttribute $field) => $field->is_searchable && in_array((string) $field->type, ['string', 'text'], true))
            ->values()->all();
    }

    /**
     * Non-archived custom fields of the resource.
     *
     * @return \Illuminate\Support\Collection<int, MetaAttribute>
     */
    private function activeFields(string $resourceKey): \Illuminate\Support\Collection
    {
        return ($this->fields ?? app(CustomFieldService::class))->fieldsFor($resourceKey);
    }

    /**
     * `EXISTS` subquery: the record holds a value of the field that passes the constraint.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(QueryBuilder): void  $constrain
     */
    private function subquery(Builder $query, MetaAttribute $field, callable $constrain): \Closure
    {
        $model = $query->getModel();
        $assignments = CoreTableConstant::META_DATA;
        $values = $this->valueTable($field);
        $type = $this->valueClass($field);

        return function (QueryBuilder $exists) use ($model, $field, $assignments, $values, $type, $constrain): void {
            $exists->selectRaw('1')
                ->from($assignments)
                ->join($values, "{$values}.id", '=', "{$assignments}.value_id")
                ->where("{$assignments}.target_type", $model->getMorphClass())
                ->whereColumn("{$assignments}.target_id", $model->getQualifiedKeyName())
                ->where("{$assignments}.meta_attribute_id", $field->getKey())
                ->where("{$assignments}.value_type", $type);
            $constrain($exists);
        };
    }

    /**
     * Value constraint for one filter, or null when the value is malformed for the field's type.
     *
     * @return (callable(QueryBuilder): void)|null
     */
    private function valueConstraint(MetaAttribute $field, mixed $raw): ?callable
    {
        $column = $this->valueTable($field).'.value';

        switch ($this->customType($field)) {
            case CustomFieldType::Select:
            case CustomFieldType::Multiselect:
                $values = array_values(array_filter(array_map(
                    fn ($v) => is_string($v) && $v !== '' ? $v : null,
                    is_array($raw) ? $raw : [$raw],
                ), fn ($v) => $v !== null));

                return $values === [] ? null : fn (QueryBuilder $q) => $q->whereIn($column, $values);

            case CustomFieldType::Boolean:
                $flag = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return is_array($raw) || $flag === null ? null : fn (QueryBuilder $q) => $q->where($column, $flag ? 1 : 0);

            case CustomFieldType::Integer:
                return $this->rangeConstraint($column, $raw, fn ($v) => is_scalar($v) && filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : null);

            case CustomFieldType::Date:
                return $this->dateConstraint($column, $raw);

            default:
                return null;
        }
    }

    /**
     * Equality (scalar) or `from`/`to` bounds (either optional) over a column.
     *
     * @param  callable(mixed): (int|string|null)  $normalize
     * @return (callable(QueryBuilder): void)|null
     */
    private function rangeConstraint(string $column, mixed $raw, callable $normalize): ?callable
    {
        if (! is_array($raw)) {
            $exact = $normalize($raw);

            return $exact === null ? null : fn (QueryBuilder $q) => $q->where($column, $exact);
        }

        $from = array_key_exists('from', $raw) ? $normalize($raw['from']) : null;
        $to = array_key_exists('to', $raw) ? $normalize($raw['to']) : null;
        if ($from === null && $to === null) {
            return null;
        }

        return function (QueryBuilder $q) use ($column, $from, $to): void {
            if ($from !== null) {
                $q->where($column, '>=', $from);
            }
            if ($to !== null) {
                $q->where($column, '<=', $to);
            }
        };
    }

    /**
     * Day equality (scalar) or inclusive `from`/`to` days over a date column.
     *
     * The upper bound is "before the next day" so a driver that stores a timestamp (SQLite) still includes the last day.
     *
     * @return (callable(QueryBuilder): void)|null
     */
    private function dateConstraint(string $column, mixed $raw): ?callable
    {
        $day = static function (mixed $v): ?string {
            if (! is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return $v;
        };
        $next = static fn (string $d): string => date('Y-m-d', strtotime($d.' +1 day'));

        $from = $to = null;
        if (is_array($raw)) {
            $from = array_key_exists('from', $raw) ? $day($raw['from']) : null;
            $to = array_key_exists('to', $raw) ? $day($raw['to']) : null;
        } else {
            $from = $to = $day($raw);
        }
        if ($from === null && $to === null) {
            return null;
        }

        return function (QueryBuilder $q) use ($column, $from, $to, $next): void {
            if ($from !== null) {
                $q->where($column, '>=', $from);
            }
            if ($to !== null) {
                $q->where($column, '<', $next($to));
            }
        };
    }

    /** Table of the typed value rows of a field. */
    private function valueTable(MetaAttribute $field): string
    {
        return (new ($this->valueClass($field)))->getTable();
    }

    /**
     * Value model class of a field's storage type.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    private function valueClass(MetaAttribute $field): string
    {
        return match ((string) $field->type) {
            'bigInt' => MetaValueBigNum::class,
            'text' => MetaValueText::class,
            'date' => MetaValueDate::class,
            'boolean' => MetaValueBoolean::class,
            default => MetaValueString::class,
        };
    }

    /** Custom field type recovered from the stored input type and multiple flag. */
    private function customType(MetaAttribute $field): ?CustomFieldType
    {
        foreach (CustomFieldType::cases() as $case) {
            if ($case->inputType() === $field->input_type && $case->isMultiple() === (bool) $field->is_multiple) {
                return $case;
            }
        }

        return null;
    }

    /** Field key recovered from the attribute name (the part after the last dot). */
    private function keyOf(MetaAttribute $field): string
    {
        $name = (string) $field->name;

        return substr($name, (int) strrpos($name, '.') + 1);
    }
}
