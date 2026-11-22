<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use InvalidArgumentException;

/**
 * Linkage-condition language shared by PHP and (later) JS implementations.
 *
 * A condition is a plain JSON-compatible array, either:
 * - a leaf `{ field, op, value? }`, or
 * - a group `{ all: [...] }` or `{ any: [...] }`, nestable. An empty `all` is
 *   true and an empty `any` is false.
 *
 * Operators: `eq`, `ne` (negation of `eq`), `in` (value is a list),
 * `empty`, `not_empty`. `eq`/`ne` require a `value` key (it may be null);
 * `in` requires a list value; `empty`/`not_empty` take no value.
 * `field` may be dotted and is resolved with `data_get`.
 *
 * Normalisation before comparing: `null` stays `null`; booleans become
 * "true"/"false"; numbers and strings become strings (`1` equals `"1"`).
 * `empty` is true for a missing field, `null`, `""` and `[]`.
 *
 * Evaluation fails closed: an invalid node evaluates to false.
 */
final class Condition
{
    private const LEAF_OPS = ['eq', 'ne', 'in', 'empty', 'not_empty'];

    /**
     * Evaluate a condition against a map of form values.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $values
     */
    public static function evaluate(array $c, array $values): bool
    {
        try {
            self::validate($c);
        } catch (InvalidArgumentException) {
            return false;
        }

        return self::run($c, $values);
    }

    /**
     * Validate a condition tree.
     *
     * @param  array<string, mixed>  $c
     *
     * @throws InvalidArgumentException when the node is malformed or uses an unknown operator
     */
    public static function validate(array $c): void
    {
        $isGroup = array_key_exists('all', $c) || array_key_exists('any', $c);
        $isLeaf = array_key_exists('field', $c) || array_key_exists('op', $c);

        if ($isGroup === $isLeaf) {
            throw new InvalidArgumentException('A condition node must be either a leaf or a group.');
        }

        if ($isGroup) {
            if (array_key_exists('all', $c) && array_key_exists('any', $c)) {
                throw new InvalidArgumentException('A group must use only one of "all" or "any".');
            }
            $key = array_key_exists('all', $c) ? 'all' : 'any';
            if (! is_array($c[$key]) || ! array_is_list($c[$key])) {
                throw new InvalidArgumentException("Group \"{$key}\" must be a list.");
            }
            foreach ($c[$key] as $child) {
                if (! is_array($child)) {
                    throw new InvalidArgumentException('Group members must be condition nodes.');
                }
                self::validate($child);
            }

            return;
        }

        if (! isset($c['field']) || ! is_string($c['field']) || $c['field'] === '') {
            throw new InvalidArgumentException('A leaf requires a non-empty string "field".');
        }
        if (! isset($c['op']) || ! is_string($c['op']) || ! in_array($c['op'], self::LEAF_OPS, true)) {
            throw new InvalidArgumentException('Unknown or missing operator.');
        }
        if (in_array($c['op'], ['eq', 'ne'], true) && ! array_key_exists('value', $c)) {
            throw new InvalidArgumentException("Operator \"{$c['op']}\" requires a value.");
        }
        if ($c['op'] === 'in' && (! isset($c['value']) || ! is_array($c['value']) || ! array_is_list($c['value']))) {
            throw new InvalidArgumentException('Operator "in" requires a list value.');
        }
    }

    /**
     * Build an `eq` leaf.
     *
     * @return array{field: string, op: string, value: mixed}
     */
    public static function eq(string $field, mixed $value): array
    {
        return ['field' => $field, 'op' => 'eq', 'value' => $value];
    }

    /**
     * Build an `in` leaf.
     *
     * @param  list<mixed>  $values
     * @return array{field: string, op: string, value: list<mixed>}
     */
    public static function in(string $field, array $values): array
    {
        return ['field' => $field, 'op' => 'in', 'value' => $values];
    }

    /**
     * Evaluate an already validated node.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $values
     */
    private static function run(array $c, array $values): bool
    {
        if (array_key_exists('all', $c)) {
            foreach ($c['all'] as $child) {
                if (! self::run($child, $values)) {
                    return false;
                }
            }

            return true;
        }
        if (array_key_exists('any', $c)) {
            foreach ($c['any'] as $child) {
                if (self::run($child, $values)) {
                    return true;
                }
            }

            return false;
        }

        $actual = data_get($values, $c['field']);

        return match ($c['op']) {
            'eq' => self::normalise($actual) === self::normalise($c['value']),
            'ne' => self::normalise($actual) !== self::normalise($c['value']),
            'in' => in_array(self::normalise($actual), array_map(self::normalise(...), $c['value']), true),
            'empty' => self::isEmpty($actual),
            'not_empty' => ! self::isEmpty($actual),
        };
    }

    /**
     * Normalise a value for comparison: null stays null, booleans and scalars become strings.
     */
    private static function normalise(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => json_encode($v),
        };
    }

    /**
     * Whether a value counts as empty: null, empty string or empty array.
     */
    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }
}
