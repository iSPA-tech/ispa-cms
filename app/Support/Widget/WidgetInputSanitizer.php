<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use Illuminate\Support\Str;

/**
 * Secure root default that allowlists and sanitizes Widget input values
 * against the canonical schema (App\Contracts\Widget\
 * CanonicalWidgetSchemaResolver), never against a caller-supplied/copied
 * schema. Any key absent from the canonical definitions is dropped
 * silently — this is the "stale copied Page schema is advisory only"
 * rejection path, and it never throws or leaks which keys were dropped.
 *
 * The sanitize rules themselves (rich-text tag allowlist, on*= stripping,
 * javascript:/vbscript:/data: scheme stripping, media URL escaping) are a
 * deliberate, independent reimplementation of
 * App\Services\WidgetQueryService::sanitizeInputValues() behavior — Task 7
 * must not modify WidgetQueryService (a later task unifies the direction
 * so the legacy service delegates to this class instead). List scalars stay
 * raw here because their Blade templates escape them at the final HTML sink.
 */
final class WidgetInputSanitizer
{
    public function __construct(private readonly CanonicalWidgetSchemaResolver $schema)
    {
    }

    /**
     * @param array<string, mixed> $values raw/possibly-stale caller values
     * @param array<string, array<string, mixed>>|null $definitions canonical schema the caller already resolved for this pair
     * @return array<string, mixed> sanitized values restricted to canonical keys only
     */
    public function sanitize(int $widgetId, int $themeId, array $values, ?array $definitions = null): array
    {
        $definitions ??= $this->schema->resolve($widgetId, $themeId);

        if ($definitions === []) {
            return [];
        }

        $allowed = array_intersect_key($values, $definitions);

        foreach ($allowed as $name => $value) {
            $type = $definitions[$name]['type'] ?? 'text';

            if ($type === 'repeater') {
                $allowed[$name] = $this->sanitizeRepeater($value, (array) ($definitions[$name]['options'] ?? []));
            } elseif (in_array($type, ['image', 'upload'], true)) {
                $allowed[$name] = $this->sanitizeMediaValue($value);
            } elseif ($type === 'textarea') {
                $allowed[$name] = $this->sanitizeRichText((string) $value);
            } elseif ($type === 'list' && is_array($value)) {
                $allowed[$name] = array_map(
                    static fn ($item) => is_scalar($item) ? (string) $item : $item,
                    $value
                );
            } elseif (is_scalar($value)) {
                $allowed[$name] = e((string) $value);
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $options repeater definition options (fields, max)
     * @return list<array<string, mixed>>
     */
    private function sanitizeRepeater(mixed $value, array $options): array
    {
        if (!is_array($value)) {
            return [];
        }

        $max = isset($options['max']) && (int) $options['max'] > 0 ? min((int) $options['max'], 100) : 12;

        $fields = [];
        foreach ((array) ($options['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name']) && $field['name'] !== '') {
                $fields[$field['name']] = (string) ($field['type'] ?? 'text');
            }
        }

        $items = [];
        $seen = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (count($items) >= $max) {
                break;
            }

            $id = $item['_id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9]{1,16}\z/', $id) !== 1 || isset($seen[$id])) {
                do {
                    $id = Str::random(8);
                } while (isset($seen[$id]));
            }
            $seen[$id] = true;

            $clean = ['_id' => $id];
            foreach ($fields as $field => $type) {
                if (!array_key_exists($field, $item)) {
                    continue;
                }
                $clean[$field] = $this->sanitizeField($type, $item[$field]);
            }

            $items[] = $clean;
        }

        return $items;
    }

    private function sanitizeField(string $type, mixed $value): mixed
    {
        if (in_array($type, ['image', 'upload'], true)) {
            return $this->sanitizeMediaValue($value);
        }

        if ($type === 'textarea') {
            return is_scalar($value) ? $this->sanitizeRichText((string) $value) : '';
        }

        return is_scalar($value) ? e((string) $value) : '';
    }

    private function sanitizeRichText(string $value): string
    {
        $value = strip_tags($value, '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><h2><h3><h4><a>');
        $value = (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value);

        return (string) preg_replace('/(?:javascript|vbscript|data)\s*:/i', '', $value);
    }

    private function sanitizeMediaValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if ($key === 'url' && is_scalar($item)) {
                $url = trim((string) $item);

                $sanitized[$key] = preg_match('/^(?:javascript|vbscript|data):/i', $url) === 1
                    ? ''
                    : e($url);
                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitizeMediaValue($item) : $item;
        }

        return $sanitized;
    }
}
