<?php

declare(strict_types=1);

namespace App\Support\Widget;

/**
 * Expands {repeat:NAME template=`...`} tokens into one copy of the template per item.
 *
 * Pure string/array work: every item field becomes a unique flat placeholder `{NAME.ID.FIELD}`
 * whose value is returned separately, so item data is bound later like any top-level field and
 * is never pasted into the template.
 */
final class WidgetRepeater
{
    private const REPEAT_PATTERN = '/\{repeat:([a-z][a-z0-9_]*)\s+template=`([\s\S]*?)`}/';

    /**
     * @param  array<string,mixed>  $values
     * @param  array<string,array<string,mixed>>  $definitions
     * @return array{content: string, values: array<string,mixed>, types: array<string,string>, repeaters: array<string,array<string,mixed>>}
     */
    public static function expand(string $content, array $values, array $definitions, bool $editMode): array
    {
        $types = [];
        $repeaters = [];
        $consumed = [];

        $expanded = preg_replace_callback(
            self::REPEAT_PATTERN,
            function (array $match) use (&$values, &$types, &$repeaters, &$consumed, $definitions, $editMode): string {
                $name = $match[1];
                $definition = $definitions[$name] ?? null;

                if (! is_array($definition) || ($definition['type'] ?? null) !== 'repeater') {
                    return '';
                }

                $consumed[$name] = true;

                return self::expandOne($name, $match[2], $definition, $values, $types, $repeaters, $editMode);
            },
            $content
        );

        if ($expanded === null || $consumed === [] && $expanded === $content) {
            return ['content' => $content, 'values' => $values, 'types' => [], 'repeaters' => []];
        }

        foreach (array_keys($consumed) as $name) {
            unset($values[$name]);
        }

        return ['content' => $expanded, 'values' => $values, 'types' => $types, 'repeaters' => $repeaters];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $values
     * @param  array<string,string>  $types
     * @param  array<string,array<string,mixed>>  $repeaters
     */
    private static function expandOne(
        string $name,
        string $template,
        array $definition,
        array &$values,
        array &$types,
        array &$repeaters,
        bool $editMode
    ): string {
        $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];
        $fields = [];
        foreach ((array) ($options['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['name'] ?? null) && $field['name'] !== '') {
                $fields[$field['name']] = (string) ($field['type'] ?? 'text');
            }
        }

        $source = $values[$name] ?? [];
        $items = is_array($source) ? array_values($source) : [];
        $max = isset($options['max']) ? (int) $options['max'] : 0;
        if ($max > 0) {
            $items = array_slice($items, 0, $max);
        }

        $out = '';
        $position = 0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! $editMode && self::isEmptyItem($item, array_keys($fields))) {
                continue;
            }

            $position++;
            $id = isset($item['_id']) && is_string($item['_id']) && preg_match('/^[A-Za-z0-9]{1,16}\z/', $item['_id'])
                ? $item['_id']
                : 'i'.($index + 1);

            foreach ($fields as $field => $type) {
                $key = $name.'.'.$id.'.'.$field;
                $value = $item[$field] ?? null;
                $values[$key] = $value === null || $value === ''
                    ? (in_array($type, ['image', 'upload'], true) ? [] : '')
                    : $value;
                $types[$key] = $type;
            }
            $values[$name.'.'.$id.'.#'] = (string) $position;
            $types[$name.'.'.$id.'.#'] = 'text';

            $copy = self::rewrite($template, $name, $id, $fields);
            if ($editMode) {
                $copy = self::tagRoot($copy, $name, $id, $position);
            }
            $out .= $copy;
        }

        $repeaters[$name] = [
            'count' => $position,
            'max' => $max,
            'item_label' => $options['item_label'] ?? null,
            'fields' => array_values((array) ($options['fields'] ?? [])),
        ];

        return $out;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<string>  $fieldNames
     */
    private static function isEmptyItem(array $item, array $fieldNames): bool
    {
        foreach ($fieldNames as $field) {
            $value = $item[$field] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string>  $fields
     */
    private static function rewrite(string $template, string $name, string $id, array $fields): string
    {
        $quoted = preg_quote($name, '/');

        // {image name="NAME.FIELD" ...} and {upload name="NAME.FIELD"}.
        $template = preg_replace_callback(
            '/(\{(?:image|upload)\b[^}]*?\bname=")'.$quoted.'\.([A-Za-z0-9_]+)(")/',
            fn (array $m): string => isset($fields[$m[2]]) ? $m[1].$name.'.'.$id.'.'.$m[2].$m[3] : $m[0],
            $template
        ) ?? $template;

        // {NAME.FIELD} and {NAME.#}; undeclared fields become empty.
        return preg_replace_callback(
            '/\{'.$quoted.'\.([A-Za-z0-9_]+|#)\}/',
            function (array $m) use ($fields, $name, $id): string {
                if ($m[1] === '#' || isset($fields[$m[1]])) {
                    return '{'.$name.'.'.$id.'.'.$m[1].'}';
                }

                return '';
            },
            $template
        ) ?? $template;
    }

    private static function tagRoot(string $copy, string $name, string $id, int $position): string
    {
        return preg_replace(
            '/<([A-Za-z][A-Za-z0-9-]*)/',
            '<$1 data-repeat="'.$name.'" data-item-id="'.$id.'" data-item-index="'.$position.'"',
            $copy,
            1
        ) ?? $copy;
    }
}
