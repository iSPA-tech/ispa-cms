<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use App\Contracts\Widget\WidgetAreaUsageCounter;
use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\PageWidgetBlockWalker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where widgets are used on this site, for the widget library.
 *
 * Pages: widget blocks in `posts.content` (block lists, nested blocks found by {@see PageWidgetBlockWalker}) and single
 * widget blocks in `contents.custom` (RealEstate property content; skipped when the table or column is absent). Each
 * non-deleted post row is one page (translations are sibling rows, so each language counts); a `contents` row counts
 * once per owner (target_type + target_id); trashed content rows and trashed RealEstate property owners are skipped. A block matches a widget like {@see WidgetCloneService} matches it: by
 * widget_key when the block has both widget_key and theme_key, otherwise by widget_id.
 *
 * Areas: {@see WidgetAreaUsageCounter} (0 while the Widget module is off).
 *
 * Every call scans the stored content once for all requested widgets, so a list page costs one scan, not one per row.
 */
class WidgetUsageService
{
    public function __construct(
        private readonly PageWidgetBlockWalker $walker,
        private readonly WidgetAreaUsageCounter $areas,
    ) {
    }

    /**
     * @param iterable<Widget> $widgets
     * @return array<int, array{pages: int, areas: int}> widget id => usage
     */
    public function summaries(iterable $widgets): array
    {
        $widgets = collect($widgets)->filter(static fn ($w): bool => $w instanceof Widget)->values();
        if ($widgets->isEmpty()) {
            return [];
        }

        $ids = $widgets->map(static fn (Widget $w): int => (int) $w->id)->all();
        $pages = $this->scan($widgets);
        $areas = $this->areas->countMany($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['pages' => count($pages[$id] ?? []), 'areas' => (int) ($areas[$id] ?? 0)];
        }

        return $out;
    }

    /** @return array{pages: list<array{post_id: int|string|null, title: string, type: string, edit_route: ?string}>, areas: int} */
    public function details(Widget $widget): array
    {
        $pages = array_values($this->scan(collect([$widget]))[(int) $widget->id] ?? []);

        return ['pages' => $pages, 'areas' => $this->areas->count((int) $widget->id)];
    }

    /**
     * Designs of one widget in use: theme ids shown by any page block (matched like {@see self::scan()}: by
     * widget_key + theme_key, otherwise widget_id + template_id) or by a shared-area placement. Trashed RealEstate
     * owners are not filtered here, so this errs on the side of "in use".
     *
     * @return list<int> distinct theme ids, ascending
     */
    public function themeIdsInUse(Widget $widget): array
    {
        $idByKey = $widget->template()->pluck('id', 'theme_key')->map(static fn ($id): int => (int) $id)->all();
        $ids = [];
        $collect = function (array $block) use ($widget, $idByKey, &$ids): array {
            $key = (string) ($block['widget_key'] ?? '');
            $themeKey = (string) ($block['theme_key'] ?? '');
            if ($key !== '' && $themeKey !== '') {
                if ($key === (string) $widget->widget_key && isset($idByKey[$themeKey])) {
                    $ids[$idByKey[$themeKey]] = true;
                }
            } elseif ((int) ($block['widget_id'] ?? 0) === (int) $widget->id && (int) ($block['template_id'] ?? 0) > 0) {
                $ids[(int) $block['template_id']] = true;
            }

            return $block;
        };

        foreach ([['posts', 'content', false], ['contents', 'custom', true]] as [$table, $column, $single]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }
            $query = DB::table($table)->select(['id', $column])->whereNotNull($column)->where($column, 'like', '%widget%');
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $query->orderBy('id')->chunkById(200, function (Collection $rows) use ($column, $single, $collect): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->{$column}, true);
                    if (is_array($decoded)) {
                        $this->walker->walk($single ? [$decoded] : $decoded, $collect);
                    }
                }
            });
        }

        foreach ($this->areas->themeIdsInUse((int) $widget->id) as $id) {
            $ids[(int) $id] = true;
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * One pass over the stored content.
     *
     * @param Collection<int, Widget> $widgets
     * @return array<int, array<string, array{post_id: int|string|null, title: string, type: string, edit_route: ?string}>>
     *         widget id => page key => page
     */
    private function scan(Collection $widgets): array
    {
        $byKey = [];
        $byId = [];
        foreach ($widgets as $widget) {
            $byKey[(string) $widget->widget_key] = (int) $widget->id;
            $byId[(int) $widget->id] = (int) $widget->id;
        }

        $match = static function (array $block) use ($byKey, $byId): ?int {
            $key = (string) ($block['widget_key'] ?? '');
            if ($key !== '' && (string) ($block['theme_key'] ?? '') !== '') {
                return $byKey[$key] ?? null;
            }

            return $byId[(int) ($block['widget_id'] ?? 0)] ?? null;
        };

        $found = [];
        $this->scanPosts($match, $found);
        $this->scanContents($match, $found);

        return $found;
    }

    private function scanPosts(callable $match, array &$found): void
    {
        if (!Schema::hasTable('posts') || !Schema::hasColumn('posts', 'content')) {
            return;
        }

        $query = DB::table('posts')->select(['id', 'uuid', 'name', 'type', 'content'])
            ->whereNotNull('content')
            // Cheap prefilter: every widget block holds the word "widget".
            ->where('content', 'like', '%widget%');
        if (Schema::hasColumn('posts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->orderBy('id')->chunkById(200, function (Collection $rows) use ($match, &$found): void {
            foreach ($rows as $row) {
                foreach ($this->matchedIds($row->content, $match, false) as $widgetId) {
                    $found[$widgetId]['post:' . $row->id] = [
                        'post_id' => (int) $row->id,
                        'title' => (string) $row->name,
                        'type' => (string) ($row->type ?? 'post'),
                        'edit_route' => $row->uuid ? $this->postEditPath((string) $row->type) . $row->uuid : null,
                    ];
                }
            }
        });
    }

    /** Admin editor path prefix per post type (Content admin routes); QA questions have their own editor. */
    private function postEditPath(string $type): string
    {
        return $type === 'qa' ? '/post/qa/question/update/' : '/post/update/';
    }

    private function scanContents(callable $match, array &$found): void
    {
        if (!Schema::hasTable('contents') || !Schema::hasColumn('contents', 'custom')) {
            return;
        }

        $query = DB::table('contents')->select(['id', 'name', 'target_type', 'target_id', 'custom'])
            ->whereNotNull('custom')
            ->where('custom', 'like', '%widget%');
        if (Schema::hasColumn('contents', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $owners = [];
        $query->orderBy('id')->chunkById(200, function (Collection $rows) use ($match, &$found, &$owners): void {
            foreach ($rows as $row) {
                foreach ($this->matchedIds($row->custom, $match, true) as $widgetId) {
                    $type = str((string) class_basename((string) $row->target_type))->snake()->toString() ?: 'content';
                    $ownerKey = 'content:' . $row->target_type . ':' . $row->target_id;
                    $found[$widgetId][$ownerKey] ??= [
                        'post_id' => is_numeric($row->target_id) ? (int) $row->target_id : $row->target_id,
                        'title' => (string) $row->name,
                        'type' => $type,
                        'edit_route' => null,
                    ];
                    $owners[$type][] = $row->target_id;
                }
            }
        });

        $this->linkRealEstateProperties($owners['real_estate_property'] ?? [], $found);
    }

    /**
     * RealEstate property content: title and admin edit route from the property row (read by table name, Core does
     * not depend on the RealEstate module; skipped when the table is absent).
     */
    private function linkRealEstateProperties(array $ids, array &$found): void
    {
        if ($ids === [] || !Schema::hasTable('real_estate_properties')) {
            return;
        }

        $columns = ['id', 'uuid', 'name'];
        $hasTrash = Schema::hasColumn('real_estate_properties', 'deleted_at');
        if ($hasTrash) {
            $columns[] = 'deleted_at';
        }
        $properties = DB::table('real_estate_properties')->whereIn('id', array_unique($ids))->get($columns)->keyBy('id');
        foreach ($found as $widgetId => $pages) {
            foreach ($pages as $key => $page) {
                $property = $page['type'] === 'real_estate_property' ? ($properties[$page['post_id']] ?? null) : null;
                // Content of a trashed property is not a page in use.
                if ($property !== null && $hasTrash && $property->deleted_at !== null) {
                    unset($found[$widgetId][$key]);
                    continue;
                }
                if ($property !== null && $property->uuid) {
                    $found[$widgetId][$key]['edit_route'] = '/real-estate/property/update/' . $property->uuid;
                    $found[$widgetId][$key]['title'] = (string) ($property->name ?: $page['title']);
                }
            }
        }
    }

    /** @return list<int> Matched widget ids in one stored value (each once). */
    private function matchedIds(mixed $raw, callable $match, bool $single): array
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        $this->walker->walk($single ? [$decoded] : $decoded, static function (array $block) use ($match, &$ids): array {
            $id = $match($block);
            if ($id !== null) {
                $ids[$id] = $id;
            }

            return $block;
        });

        return array_values($ids);
    }
}
