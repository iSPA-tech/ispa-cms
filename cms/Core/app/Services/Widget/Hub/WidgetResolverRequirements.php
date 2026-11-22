<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

/**
 * The data resolvers a set of designs needs, as `resolver key => minimum version`.
 *
 * Designs reach data only through `{query:type.name ...}` placeholders in their Blade and the `query` column; both map
 * to `query:<type.name>` at version 1. A design cannot name a Widget module provider: provider references
 * (`provider_refs`) live on Site Layout widget instances, not on `widget_themes`, so bundle requirements carry only
 * `query:*` entries today. A `provider:<key>` requirement is still understood by {@see WidgetResolverInventory}.
 */
final class WidgetResolverRequirements
{
    private const QUERY_PLACEHOLDER = '/\{query:(\w+)\.(\w+)/';
    private const QUERY_NAME = '/\A\w+\.\w+\z/';

    /**
     * @param iterable<array{content?: ?string, query?: mixed}> $designs
     * @return array<string, int> sorted by key
     */
    public static function forDesigns(iterable $designs): array
    {
        $requires = [];
        foreach ($designs as $design) {
            if (preg_match_all(self::QUERY_PLACEHOLDER, (string) ($design['content'] ?? ''), $matches) > 0) {
                foreach ($matches[1] as $index => $type) {
                    $requires['query:' . $type . '.' . $matches[2][$index]] = 1;
                }
            }
            foreach (self::queryColumnNames($design['query'] ?? null) as $name) {
                $requires['query:' . $name] = 1;
            }
        }
        ksort($requires);

        return $requires;
    }

    /**
     * Query names in a design's `query` column (decoded JSON or its raw string): any `type.name` string value or key.
     *
     * @return list<string>
     */
    private static function queryColumnNames(mixed $query): array
    {
        if (is_string($query)) {
            $query = json_decode($query, true);
        }
        if (!is_array($query)) {
            return [];
        }

        $names = [];
        array_walk_recursive($query, static function (mixed $value) use (&$names): void {
            if (is_string($value) && preg_match(self::QUERY_NAME, $value) === 1) {
                $names[] = $value;
            }
        });
        foreach (array_keys($query) as $key) {
            if (is_string($key) && preg_match(self::QUERY_NAME, $key) === 1) {
                $names[] = $key;
            }
        }

        return $names;
    }
}
