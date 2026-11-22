<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

use Cms\Core\Models\ResourceOverlay;
use Illuminate\Database\QueryException;

/**
 * Per-site overlay stage (spec 6.6, third step; roadmap D-10).
 *
 * Reads the site's {@see ResourceOverlay} row of the resource (one query) and applies it to the list columns and
 * filters, the create and update form fields and the detail sections. Without a row the schema is returned unchanged.
 * Nothing in the admin writes overlays yet.
 *
 * - hidden: entries named in the overlay are removed. A field that is required in a form is never removed from that
 *   form (create or update), because hiding it would make every save fail; it is still hidden from list and detail.
 *   List search fields are left alone: hiding a column does not change what the search matches.
 * - order: named entries come first in the overlay order, the others follow in their original relative order.
 * - labels: `{field: {locale: text}}`. The text of the context locale (else the other locale) is set as the literal
 *   `label_text` of that locale, so it wins over the definition key and over a custom field's own label. The original
 *   `label` key is kept.
 *
 * A missing `resource_overlays` table (migration not run yet) counts as no overlay. Unknown field names and malformed values (a non-array list or label map) are ignored.
 */
final class OverlayStage implements SchemaStage
{
    /** Apply the overlay of the resource, if the site has one. */
    public function apply(array $schema, SchemaContext $context): array
    {
        try {
            $overlay = ResourceOverlay::forResource($context->resource->key());
        } catch (QueryException) {
            // The table is missing (migration not run yet): behave as if the site had no overlay.
            return $schema;
        }
        if ($overlay === null) {
            return $schema;
        }

        $hidden = $this->names($overlay->hidden);
        $order = $this->names($overlay->order);
        $labels = is_array($overlay->labels) ? $overlay->labels : [];
        $locale = $context->locale;

        $transform = fn (array $entries, bool $keepRequired = false): array => $this->relabel(
            $this->reorder($this->hide($entries, $hidden, $keepRequired), $order),
            $labels,
            $locale,
        );

        if (is_array($schema['list'] ?? null)) {
            foreach (['columns', 'filters'] as $key) {
                if (isset($schema['list'][$key])) {
                    $schema['list'][$key] = $this->keepDetailLink($schema['list'][$key], $transform($schema['list'][$key]));
                }
            }
        }
        if (is_array($schema['form'] ?? null)) {
            foreach (['create', 'update'] as $form) {
                if (isset($schema['form'][$form]['fields'])) {
                    $schema['form'][$form]['fields'] = $transform($schema['form'][$form]['fields'], true);
                }
            }
        }
        if (is_array($schema['detail'] ?? null) && isset($schema['detail']['sections'])) {
            $schema['detail']['sections'] = $transform($schema['detail']['sections']);
        }

        return $schema;
    }

    /**
     * Keep the `detail` link on the first column when the original first column carried it.
     *
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<int, array<string, mixed>>
     */
    private function keepDetailLink(array $before, array $after): array
    {
        $linked = ($before[0]['link'] ?? null) === 'detail';
        if (! $linked || $after === []) {
            return $after;
        }
        foreach ($after as $i => $entry) {
            if (($entry['link'] ?? null) === 'detail') {
                unset($after[$i]['link']);
            }
        }
        $after[0]['link'] = 'detail';

        return $after;
    }

    /**
     * A list of field names from a stored JSON value; anything else is empty.
     *
     * @return array<int, string>
     */
    private function names(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Remove the entries whose field is hidden, except required form fields when asked.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $hidden
     * @return array<int, array<string, mixed>>
     */
    private function hide(array $entries, array $hidden, bool $keepRequired): array
    {
        if ($hidden === []) {
            return $entries;
        }

        return array_values(array_filter($entries, static fn (array $entry): bool => ! in_array($entry['field'] ?? null, $hidden, true)
            || ($keepRequired && ($entry['rules']['required'] ?? false) === true)));
    }

    /**
     * Put the ordered fields first; the rest keep their relative order.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $order
     * @return array<int, array<string, mixed>>
     */
    private function reorder(array $entries, array $order): array
    {
        if ($order === []) {
            return $entries;
        }

        $first = [];
        foreach (array_unique($order) as $name) {
            foreach ($entries as $i => $entry) {
                if (($entry['field'] ?? null) === $name) {
                    $first[] = $entry;
                    unset($entries[$i]);
                    break;
                }
            }
        }

        return array_values(array_merge($first, $entries));
    }

    /**
     * Set the literal label of the locale on the entries that have an override.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $labels
     * @return array<int, array<string, mixed>>
     */
    private function relabel(array $entries, array $labels, string $locale): array
    {
        foreach ($entries as $i => $entry) {
            $texts = $labels[$entry['field'] ?? ''] ?? null;
            if (! is_array($texts)) {
                continue;
            }
            $text = $texts[$locale] ?? ($locale === 'vi' ? ($texts['en'] ?? null) : ($texts['vi'] ?? null));
            if (! is_string($text) || $text === '') {
                continue;
            }
            $current = is_array($entry['label_text'] ?? null) ? $entry['label_text'] : [];
            $entries[$i]['label_text'] = [$locale => $text] + $current;
        }

        return $entries;
    }
}
