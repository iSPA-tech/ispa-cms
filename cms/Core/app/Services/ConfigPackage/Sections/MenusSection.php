<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Models\Enumerate;
use Cms\Core\Models\Language;
use Cms\Core\Models\Menu;
use Cms\Core\Models\MenuItem;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Illuminate\Support\Facades\DB;

/**
 * Menus section of the configuration package.
 *
 * Row format: `{ group_key, language, name, status, items, skipped_items }`.
 * - `language` is the language `path_alias ?? path` (null when the menu has no language); `language_id` never leaves the site.
 * - A menu is matched by its stable key `group_key|language|name` (this string is also the diff item `key`).
 * - `items` is a nested tree (`children` of an item are in its own `items`), each item being
 *   `{ name, title, url, type, custom, items }`; the order of siblings is their priority, so no id, parent id,
 *   target id or priority is carried.
 * - `skipped_items` lists `{ name, reason }` of items that were not exported (informational, ignored on import).
 *
 * Items pointing at a numeric record (`target_id` set or non-empty `custom.categoryId`) are not exported and are
 * counted in `skipped['points_at_record']`; descendants of any skipped item (and items without a name) are skipped
 * too (`parent_skipped`, `unnamed`). The package therefore never contains a numeric id of any table.
 *
 * Item key (matching inside one menu): the names from the root down joined with ` / `, where the n-th sibling
 * (n >= 2) sharing a name gets `#n` appended to its own name, for example `Products / Shoes#2`.
 *
 * Import is additive: menu fields (status) are updated, missing items are added under their matched parent, and
 * existing items are never changed or removed. The only destructive operation is the per-menu choice
 * `<menu key>#replace_items` (off by default): it soft-deletes every current item of that menu and recreates the
 * package tree. Everything goes through the Eloquent models so observers fire.
 */
class MenusSection implements SectionAdapter
{
    /** Suffix of the per-menu choice key that turns "replace items" on. */
    public const REPLACE_CHOICE_SUFFIX = '#replace_items';

    /** @var array<string, array{group: string, language: int|null}> Menu groups changed by apply, keyed for cache clearing. */
    private array $touched = [];

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'menus';
    }

    /**
     * @inheritDoc
     *
     * The context key patterns are setting key patterns and are not used here.
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];
        $skipped = [];
        $menus = Menu::query()->with('language')->orderBy('group_key')->orderBy('name')->orderBy('id')->get();
        $keyCounts = $menus->countBy(fn (Menu $menu): string => $this->menuKeyOf($menu));

        foreach ($menus as $menu) {
            if ($menu->language_id !== null && $menu->language === null) {
                $skipped['language_missing'] = ($skipped['language_missing'] ?? 0) + 1;

                continue;
            }

            if (($keyCounts[$this->menuKeyOf($menu)] ?? 0) > 1) {
                $skipped['ambiguous'] = ($skipped['ambiguous'] ?? 0) + 1;

                continue;
            }

            [$items, $skippedItems] = $this->exportItems($menu);
            foreach ($skippedItems as $skippedItem) {
                $skipped[$skippedItem['reason']] = ($skipped[$skippedItem['reason']] ?? 0) + 1;
            }

            $rows[] = [
                'group_key' => trim((string) $menu->group_key),
                'language' => $this->languagePath($menu->language),
                'name' => trim((string) $menu->name),
                'status' => $menu->status,
                'items' => $items,
                'skipped_items' => $skippedItems,
            ];
        }

        ksort($skipped);

        return new SectionExport($rows, $skipped);
    }

    /**
     * @inheritDoc
     *
     * Items carry `key`, `action`, `reason`, `before`, `after`, `warnings`, `items_to_add` and, for an existing menu,
     * `choices` with one off-by-default `replace_items` choice that reports how many target items it would remove.
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $packageCounts = $this->packageKeyCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $item = $this->diffRow(is_array($row) ? $row : [], $packageCounts);
            unset($item['menu_id'], $item['language_id'], $item['plan']);
            $items[] = $item;
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     *
     * `created` and `updated` menus are both counted in ApplyResult::$updated. Per-item outcomes carry
     * `added_items` and `removed_items`.
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $packageCounts = $this->packageKeyCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            // Fresh state per row: earlier rows may have created menus.
            $diff = $this->diffRow($row, $packageCounts);
            $key = (string) $diff['key'];
            $reason = $diff['reason'];
            $added = 0;
            $removed = 0;

            if ($diff['action'] === 'blocked') {
                $outcome = 'blocked';
                ++$counts['blocked'];
            } elseif (!$c->isChosen($key)) {
                $outcome = 'skipped';
                $reason = 'deselected';
                ++$counts['skipped'];
            } else {
                $replace = $diff['menu_id'] !== null && ($c->choices[$key . self::REPLACE_CHOICE_SUFFIX] ?? null) === true;

                if ($diff['action'] === 'unchanged' && !$replace) {
                    $outcome = 'unchanged';
                    ++$counts['unchanged'];
                } else {
                    [$outcome, $added, $removed] = $this->applyMenu($row, $diff, $replace);
                    ++$counts['updated'];
                }
            }

            $items[] = [
                'key' => $key,
                'outcome' => $outcome,
                'reason' => $reason,
                'warnings' => $diff['warnings'],
                'added_items' => $added,
                'removed_items' => $removed,
            ];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * Runs the same invalidation as MenuController after a write (`CorePublicMenuCacheService::invalidateGroup`:
     * legacy group key, language-id key, fingerprint memo, shared Redis payload, `PublicMenuInvalidated` event) for
     * every menu group changed by this instance's last apply; when none is recorded, every menu group is cleared.
     */
    public function afterApply(ApplyContext $c): void
    {
        $targets = $this->touched;

        if ($targets === []) {
            foreach (Menu::query()->select(['group_key', 'language_id'])->distinct()->get() as $menu) {
                $targets[] = ['group' => (string) $menu->group_key, 'language' => $menu->language_id];
            }
        }

        foreach ($targets as $target) {
            $this->invalidateGroup($target['group'], $target['language']);
        }

        $this->touched = [];
    }

    /**
     * Invalidate every cache layer of one menu group (seam for tests).
     */
    protected function invalidateGroup(string $group, ?int $languageId): void
    {
        app(CorePublicMenuCacheService::class)->invalidateGroup($group, $languageId);
    }

    /**
     * Build the exported item tree of a menu and the list of items that were left out.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{name: string, reason: string}>}
     */
    private function exportItems(Menu $menu): array
    {
        $all = MenuItem::query()->where('menu_id', $menu->id)->orderBy('priority')->orderBy('id')->get();
        $byParent = $all->groupBy(fn (MenuItem $item): int => (int) $item->parent_id);
        $seen = [];
        $skipped = [];

        $walk = function (int $parentId, bool $parentSkipped) use (&$walk, $byParent, &$seen, &$skipped): array {
            $out = [];

            foreach ($byParent->get($parentId, collect()) as $item) {
                $seen[(int) $item->id] = true;
                $name = trim((string) $item->name);
                $reason = $parentSkipped ? 'parent_skipped' : $this->itemSkipReason($item, $name);

                if ($reason !== null) {
                    $skipped[] = ['name' => $name, 'reason' => $reason];
                    $walk((int) $item->id, true);

                    continue;
                }

                $custom = is_array($item->custom) ? $item->custom : [];
                unset($custom['categoryId']);
                ksort($custom);

                $out[] = [
                    'name' => $name,
                    'title' => $item->title,
                    'url' => $item->url,
                    'type' => $item->type,
                    'custom' => $custom,
                    'items' => $walk((int) $item->id, false),
                ];
            }

            return $out;
        };

        $tree = $walk(0, false);

        // Items whose parent is gone (or part of a cycle) are unreachable from the roots.
        foreach ($all as $item) {
            if (!isset($seen[(int) $item->id])) {
                $skipped[] = ['name' => trim((string) $item->name), 'reason' => 'parent_skipped'];
            }
        }

        return [$tree, $skipped];
    }

    /**
     * Reason a menu item cannot be exported, or null.
     */
    private function itemSkipReason(MenuItem $item, string $name): ?string
    {
        if ($item->target_id !== null && (int) $item->target_id !== 0) {
            return 'points_at_record';
        }

        $custom = is_array($item->custom) ? $item->custom : [];
        if (!empty($custom['categoryId'])) {
            return 'points_at_record';
        }

        return $name === '' ? 'unnamed' : null;
    }

    /**
     * Compare one incoming menu with the target.
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $packageCounts
     *
     * @return array<string, mixed>
     */
    private function diffRow(array $row, array $packageCounts): array
    {
        $group = is_string($row['group_key'] ?? null) ? trim($row['group_key']) : '';
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $path = is_string($row['language'] ?? null) && trim($row['language']) !== '' ? trim($row['language']) : null;
        $key = $this->menuKey($group, $path, $name);
        $item = [
            'key' => $key,
            'action' => 'blocked',
            'reason' => null,
            'before' => null,
            'after' => null,
            'warnings' => [],
            'items_to_add' => 0,
            'menu_id' => null,
            'plan' => [],
        ];

        if ($group === '' || $name === '') {
            $item['reason'] = 'menu group key or name is missing';

            return $item;
        }

        if (($packageCounts[$key] ?? 0) > 1) {
            $item['reason'] = 'ambiguous: the package lists this menu more than once';

            return $item;
        }

        $status = $row['status'] ?? null;
        if ($status !== null && !is_string($status)) {
            $item['reason'] = 'menu status must be text';

            return $item;
        }

        $status = $status === null ? null : trim($status);
        if ($status === '') {
            $status = null;
        }

        if ($status !== null && !Enumerate::query()->where('enum_key', $status)->exists()) {
            $item['reason'] = 'status ' . $status . ' does not exist on this site';

            return $item;
        }

        $language = null;
        if ($path !== null) {
            $language = Language::query()->where('path_alias', $path)->first() ?? Language::query()->where('path', $path)->first();

            if ($language === null) {
                $item['reason'] = 'language ' . $path . ' does not exist on this site';

                return $item;
            }
        }

        $incoming = $row['items'] ?? [];
        $flat = [];
        if (!is_array($incoming) || !$this->flattenIncoming($incoming, '', null, $flat)) {
            $item['reason'] = 'menu items are malformed or an item has no name';

            return $item;
        }

        $languageId = $language !== null ? (int) $language->id : null;
        $targets = Menu::query()->where('group_key', $group)->where('language_id', $languageId)->orderBy('id')->get()
            ->filter(fn (Menu $menu): bool => trim((string) $menu->name) === $name)->values();

        if ($targets->count() > 1) {
            $item['reason'] = 'ambiguous: this menu matches ' . $targets->count() . ' menus on this site';

            return $item;
        }

        $item['language_id'] = $languageId;

        if ($targets->isEmpty()) {
            $item['action'] = 'create';
            $item['after'] = ['status' => $status, 'items' => count($flat)];
            $item['items_to_add'] = count($flat);
            $item['plan'] = $flat;

            return $item;
        }

        /** @var Menu $menu */
        $menu = $targets->first();
        $targetMap = $this->targetItemMap($menu);
        $toAdd = array_values(array_filter($flat, static fn (array $node): bool => !isset($targetMap['keys'][$node['key']])));

        $item['menu_id'] = (int) $menu->id;
        $item['plan'] = $flat;
        $item['items_to_add'] = count($toAdd);
        $item['before'] = ['status' => $menu->status, 'items' => count($targetMap['keys'])];
        $item['after'] = ['status' => $status, 'items' => count($targetMap['keys']) + count($toAdd)];
        $item['choices'] = [[
            'key' => $key . self::REPLACE_CHOICE_SUFFIX,
            'name' => 'replace_items',
            'default' => false,
            'removes' => count($targetMap['keys']),
            'adds' => count($flat),
        ]];
        $item['needs_choice'] = false;
        $item['default_choice'] = false;

        $statusChanged = ($menu->status ?? null) !== $status;
        $item['action'] = $statusChanged || $toAdd !== [] ? 'update' : 'unchanged';

        return $item;
    }

    /**
     * Apply one validated menu row.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $diff
     *
     * @return array{0: string, 1: int, 2: int} Outcome, added items, removed items.
     */
    private function applyMenu(array $row, array $diff, bool $replace): array
    {
        $status = is_string($row['status'] ?? null) && trim($row['status']) !== '' ? trim($row['status']) : null;
        $added = 0;
        $removed = 0;

        $outcome = DB::transaction(function () use ($row, $diff, $replace, $status, &$added, &$removed): string {
            if ($diff['menu_id'] === null) {
                $menu = Menu::query()->create([
                    'group_key' => trim((string) $row['group_key']),
                    'name' => trim((string) $row['name']),
                    'language_id' => $diff['language_id'],
                    'status' => $status,
                ]);
                $outcome = 'created';
            } else {
                $menu = Menu::query()->findOrFail($diff['menu_id']);
                if (($menu->status ?? null) !== $status) {
                    $menu->fill(['status' => $status])->save();
                }
                $outcome = 'updated';
            }

            if ($replace) {
                foreach (MenuItem::query()->where('menu_id', $menu->id)->get() as $old) {
                    $old->delete();
                    ++$removed;
                }
            }

            $targetMap = $replace || $diff['menu_id'] === null
                ? ['keys' => [], 'next' => []]
                : $this->targetItemMap($menu);
            $keyToId = $targetMap['keys'];
            $next = $targetMap['next'];

            foreach ($diff['plan'] as $node) {
                if (isset($keyToId[$node['key']])) {
                    continue;
                }

                $parentId = $node['parent'] === null ? 0 : (int) $keyToId[$node['parent']];
                $priority = $next[$parentId] ?? 0;
                $next[$parentId] = $priority + 1;

                $created = MenuItem::query()->create([
                    'menu_id' => $menu->id,
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'name' => $node['data']['name'],
                    'title' => $node['data']['title'],
                    'url' => $node['data']['url'],
                    'type' => $node['data']['type'],
                    'target_type' => null,
                    'target_id' => null,
                    'custom' => $node['data']['custom'] === [] ? null : $node['data']['custom'],
                    'priority' => $priority,
                ]);
                $keyToId[$node['key']] = (int) $created->id;
                ++$added;
            }

            $this->touched[(string) $menu->group_key . '|' . (string) $menu->language_id] = [
                'group' => (string) $menu->group_key,
                'language' => $menu->language_id !== null ? (int) $menu->language_id : null,
            ];

            return $outcome;
        });

        return [$outcome, $added, $removed];
    }

    /**
     * Flatten an incoming nested item list in pre-order with stable item keys.
     *
     * @param array<mixed> $items
     * @param array<int, array{key: string, parent: string|null, data: array<string, mixed>}> $flat
     *
     * @return bool False when an item is malformed.
     */
    private function flattenIncoming(array $items, string $parentKey, ?string $parent, array &$flat): bool
    {
        $seen = [];

        foreach ($items as $incoming) {
            if (!is_array($incoming) || !is_string($incoming['name'] ?? null) || trim($incoming['name']) === '') {
                return false;
            }

            foreach (['title', 'url', 'type'] as $field) {
                if (isset($incoming[$field]) && !is_string($incoming[$field])) {
                    return false;
                }
            }

            $custom = $incoming['custom'] ?? [];
            if (!is_array($custom) || !is_array($incoming['items'] ?? [])) {
                return false;
            }
            unset($custom['categoryId']);

            $name = trim($incoming['name']);
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $key = $this->itemKey($parentKey, $name, $seen[$name]);

            $flat[] = [
                'key' => $key,
                'parent' => $parent,
                'data' => [
                    'name' => $name,
                    'title' => $incoming['title'] ?? null,
                    'url' => $incoming['url'] ?? null,
                    'type' => $incoming['type'] ?? null,
                    'custom' => $custom,
                ],
            ];

            if (!$this->flattenIncoming($incoming['items'] ?? [], $key, $key, $flat)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Item keys and per-parent next priority of the current items of a menu.
     *
     * @return array{keys: array<string, int>, next: array<int, int>}
     */
    private function targetItemMap(Menu $menu): array
    {
        $byParent = MenuItem::query()->where('menu_id', $menu->id)->orderBy('priority')->orderBy('id')->get()
            ->groupBy(fn (MenuItem $item): int => (int) $item->parent_id);
        $keys = [];
        $next = [];

        $walk = function (int $parentId, string $parentKey) use (&$walk, $byParent, &$keys, &$next): void {
            $seen = [];

            foreach ($byParent->get($parentId, collect()) as $item) {
                $name = trim((string) $item->name);
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                $key = $this->itemKey($parentKey, $name, $seen[$name]);
                $keys[$key] = (int) $item->id;
                $next[$parentId] = max($next[$parentId] ?? 0, (int) $item->priority + 1);
                $walk((int) $item->id, $key);
            }
        };

        $walk(0, '');

        return ['keys' => $keys, 'next' => $next];
    }

    /**
     * Stable key of an item inside its menu.
     */
    private function itemKey(string $parentKey, string $name, int $occurrence): string
    {
        $segment = $occurrence > 1 ? $name . '#' . $occurrence : $name;

        return $parentKey === '' ? $segment : $parentKey . ' / ' . $segment;
    }

    /**
     * Count incoming rows per menu key.
     *
     * @param list<mixed> $rows
     *
     * @return array<string, int>
     */
    private function packageKeyCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = is_string($row['language'] ?? null) && trim($row['language']) !== '' ? trim($row['language']) : null;
            $key = $this->menuKey(
                is_string($row['group_key'] ?? null) ? trim($row['group_key']) : '',
                $path,
                is_string($row['name'] ?? null) ? trim($row['name']) : '',
            );
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Stable menu key `group_key|language|name`.
     */
    private function menuKey(string $group, ?string $languagePath, string $name): string
    {
        return $group . '|' . ($languagePath ?? '') . '|' . $name;
    }

    /**
     * Stable key of a target menu.
     */
    private function menuKeyOf(Menu $menu): string
    {
        return $this->menuKey(trim((string) $menu->group_key), $this->languagePath($menu->language), trim((string) $menu->name));
    }

    /**
     * Language path used in packages: `path_alias ?? path` (same as SettingsSection).
     */
    private function languagePath(?Language $language): ?string
    {
        return $language !== null ? (string) ($language->path_alias ?? $language->path) : null;
    }
}
