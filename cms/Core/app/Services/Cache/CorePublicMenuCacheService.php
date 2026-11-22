<?php

namespace Cms\Core\Services\Cache;

use App\Constants\CacheConstants;
use App\Constants\Constant;
use App\Models\Slug;
use Cms\Core\Events\PublicMenuInvalidated;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\Category;
use Cms\Core\Models\Menu;
use Cms\Core\Models\MenuItem;
use Cms\Core\Support\MenuCategoryExpansion;
use Throwable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CorePublicMenuCacheService
{
    private const MENU_GROUP_TTL_SECONDS = 86400;
    private const ROW_DIGEST_LIMIT = 2000;
    private const FINGERPRINT_TTL_SECONDS = 60;

    /** A fingerprint wants the raw rows, not the appended-relation projections. */
    private const CATEGORY_APPENDS_SCOPE = Category::APPENDED_RELATIONS_SCOPE;

    /** @var array<string, bool> memoized Schema::hasTable() answers */
    private array $tableExistsMemo = [];

    /** @var array<string, array<int, string>> memoized column listings */
    private array $columnListingMemo = [];

    /** @var array<string, array<int, int>> memoized menu ids per group+language */
    private array $menuIdsMemo = [];

    /** @var array<string, array<int, int>> memoized category ids per group+language */
    private array $targetCategoryIdsMemo = [];

    public function rememberGroupItems(string $groupName, int|string|null $languageId, callable $loader): mixed
    {
        return Cache::remember(
            $this->groupItemsKey($groupName, $languageId),
            self::MENU_GROUP_TTL_SECONDS,
            static fn (): mixed => $loader(),
        );
    }

    /**
     * Name the cache key for a menu group.
     *
     * The name is a content fingerprint, which is what makes invalidation
     * instant — but computing it reads four tables (count, max id, max
     * updated_at, max deleted_at and a row digest each) plus a dozen schema
     * lookups, measured at roughly 45 queries. That was more than the query the
     * cache protects, so the fingerprint itself is cached for a short window.
     * A menu save clears it through forgetGroupItemsKey(), so the window only
     * matters for a change this application did not make.
     */
    public function groupItemsKey(string $groupName, int|string|null $languageId): string
    {
        $ttl = (int) config('core.public_menu.fingerprint_ttl', self::FINGERPRINT_TTL_SECONDS);

        if ($ttl <= 0) {
            return $this->computeGroupItemsKey($groupName, $languageId);
        }

        return Cache::remember(
            $this->groupItemsKeyMemoKey($groupName, $languageId),
            $ttl,
            fn (): string => $this->computeGroupItemsKey($groupName, $languageId),
        );
    }

    /**
     * Drop the memoized fingerprint so the next read recomputes it.
     *
     * Call this from anything that writes a menu; otherwise the group cache can
     * answer from the previous fingerprint for up to the configured window.
     */
    public function forgetGroupItemsKey(string $groupName, int|string|null $languageId = null): void
    {
        Cache::forget($this->groupItemsKeyMemoKey($groupName, $languageId));
    }

    /**
     * Every cache a menu write must bump, in one place — the audit that led
     * here found item-level deletes bumping none of them.
     *
     * Layers: the legacy per-group cache key, the fingerprint memo (the group
     * cache's name), the shared Redis payload the website reads directly, and
     * finally the PublicMenuInvalidated event so a project can push the
     * invalidation out to its frontend. Call it AFTER the write commits.
     */
    public function invalidateGroup(string $groupName, int|string|null $languageId = null): void
    {
        try {
            Cache::forget(sprintf(
                Constant::CACHE_MENUS[Constant::MENU_BY_GROUP_KEY],
                $groupName,
                lang('path', 'vi'),
            ));
        } catch (Throwable) {
            //  Legacy key map absent in some installs; the layers below still run.
        }

        //  The Blade footer/secondary-footer components read the group through
        //  MenuRepository::getMenuByGroupKey(), keyed by language id (24h TTL).
        //  The legacy key above uses the language path, so it never matched.
        try {
            Cache::forget(CacheKeyHelper::menuByGroupKey(
                $groupName,
                $languageId !== null && $languageId !== '' ? $languageId : null,
            ));
        } catch (Throwable) {
            //  Without a resolvable language the layers below still run.
        }

        $this->forgetGroupItemsKey($groupName, $languageId);

        //  No env guard here — getenv() is blind under a cached config, which
        //  is exactly how a stale no-expiry menu key survived in production
        //  once before. RedisHelper::del() short-circuits when Redis is off.
        RedisHelper::getInstance()->del(
            $groupName === 'main_menu'
                ? CacheConstants::CACHE_MAIN_MENU
                : CacheConstants::CACHE_FOOTER_MENU
        );

        PublicMenuInvalidated::dispatch($groupName, $languageId);
    }

    private function groupItemsKeyMemoKey(string $groupName, int|string|null $languageId): string
    {
        return 'core:public:v3:menus:group-items-key:v1:' . $this->hash([
            'group' => $groupName,
            'language_id' => $languageId,
        ]);
    }

    private function computeGroupItemsKey(string $groupName, int|string|null $languageId): string
    {
        return 'core:public:v3:menus:group-items:v1:' . $this->hash([
            'group' => $groupName,
            'language_id' => $languageId,
            'menus' => $this->menuFingerprint($groupName, $languageId),
            'items' => $this->menuItemFingerprint($groupName, $languageId),
            'category_targets' => $this->targetCategoryFingerprint($groupName, $languageId),
            'category_slugs' => $this->targetCategorySlugFingerprint($groupName, $languageId),
        ]);
    }

    /**
     * Schema shape cannot change inside one request, so ask the database once.
     *
     * hasTable() was called eight times and hasColumn() twelve times per key
     * computation, and every one of those is an information_schema round trip.
     */
    private function tableExists(string $table): bool
    {
        return $this->tableExistsMemo[$table] ??= Schema::hasTable($table);
    }

    /**
     * @return array<int, string>
     */
    private function columnListing(string $table): array
    {
        return $this->columnListingMemo[$table] ??= Schema::getColumnListing($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return in_array($column, $this->columnListing($table), true);
    }

    private function menuFingerprint(string $groupName, int|string|null $languageId): array
    {
        $table = (new Menu())->getTable();
        if (! $this->tableExists($table)) {
            return [
                'table' => $table,
                'exists' => false,
            ];
        }

        $query = Menu::query()
            ->withTrashed()
            ->where('group_key', $groupName);

        if ($languageId !== null && $languageId !== '') {
            $query->where('language_id', $languageId);
        }

        return $this->fingerprint($table, $query, [
            'id',
            'group_key',
            'name',
            'language_id',
            'status',
            'updated_at',
            'deleted_at',
        ]);
    }

    private function menuItemFingerprint(string $groupName, int|string|null $languageId): array
    {
        $menuTable = (new Menu())->getTable();
        $itemTable = (new MenuItem())->getTable();

        if (! $this->tableExists($itemTable)) {
            return [
                'table' => $itemTable,
                'exists' => false,
            ];
        }

        if (! $this->tableExists($menuTable)) {
            return [
                'table' => $itemTable,
                'exists' => true,
                'menus_table_exists' => false,
            ];
        }

        $query = MenuItem::query()
            ->withTrashed()
            ->whereIn('menu_id', $this->menuIdsForGroup($groupName, $languageId));

        return $this->fingerprint($itemTable, $query, [
            'id',
            'menu_id',
            'name',
            'title',
            'type',
            'url',
            'target_type',
            'target_id',
            'parent_id',
            'priority',
            'custom',
            'updated_at',
            'deleted_at',
        ]);
    }

    private function targetCategoryFingerprint(string $groupName, int|string|null $languageId): array
    {
        $table = (new Category())->getTable();
        if (! $this->tableExists($table)) {
            return [
                'table' => $table,
                'exists' => false,
            ];
        }

        $query = Category::query()
            ->withoutGlobalScope(self::CATEGORY_APPENDS_SCOPE)
            ->withTrashed()
            ->whereIn('id', $this->targetCategoryIds($groupName, $languageId));

        return $this->fingerprint($table, $query, [
            'id',
            'uid',
            'name',
            'description',
            'parent_id',
            'language_id',
            'priority',
            'type',
            'featured',
            'status',
            'config',
            'updated_at',
            'deleted_at',
        ]);
    }

    private function targetCategorySlugFingerprint(string $groupName, int|string|null $languageId): array
    {
        $table = (new Slug())->getTable();
        if (! $this->tableExists($table)) {
            return [
                'table' => $table,
                'exists' => false,
            ];
        }

        $query = Slug::query()
            ->whereIn('target_type', [
                Category::class,
                \App\Models\Category::class,
            ])
            ->whereIn('target_id', $this->targetCategoryIds($groupName, $languageId));

        return $this->fingerprint($table, $query, [
            'id',
            'full_uri',
            'slug',
            'category',
            'target_type',
            'target_id',
            'language_id',
            'updated_at',
            'deleted_at',
        ]);
    }

    private function menuIdsForGroup(string $groupName, int|string|null $languageId): array
    {
        $memoKey = $groupName . '|' . $languageId;
        if (array_key_exists($memoKey, $this->menuIdsMemo)) {
            return $this->menuIdsMemo[$memoKey];
        }

        $table = (new Menu())->getTable();
        if (! $this->tableExists($table)) {
            return $this->menuIdsMemo[$memoKey] = [];
        }

        $query = Menu::query()
            ->withTrashed()
            ->where('group_key', $groupName);

        if ($languageId !== null && $languageId !== '') {
            $query->where('language_id', $languageId);
        }

        return $this->menuIdsMemo[$memoKey] = (clone $query)
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    private function targetCategoryIds(string $groupName, int|string|null $languageId): array
    {
        $memoKey = $groupName . '|' . $languageId;
        if (array_key_exists($memoKey, $this->targetCategoryIdsMemo)) {
            return $this->targetCategoryIdsMemo[$memoKey];
        }

        $menuItemTable = (new MenuItem())->getTable();
        $categoryTable = (new Category())->getTable();

        if (! $this->tableExists($menuItemTable)) {
            return $this->targetCategoryIdsMemo[$memoKey] = [];
        }

        $categoryItems = MenuItem::query()
            ->withTrashed()
            ->whereIn('menu_id', $this->menuIdsForGroup($groupName, $languageId))
            ->where('type', 'category')
            ->whereNotNull('target_id')
            ->get(['target_id', 'custom']);

        $rootIds = $categoryItems
            ->pluck('target_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $expandedItems = $categoryItems
            ->filter(static fn (MenuItem $item): bool => data_get($item->custom, 'isExpandChild') === true);

        if ($rootIds === [] || $expandedItems->isEmpty() || ! $this->tableExists($categoryTable)) {
            return $this->targetCategoryIdsMemo[$memoKey] = $rootIds;
        }

        $descendantIds = [];
        foreach ($expandedItems as $item) {
            $descendantIds = array_merge(
                $descendantIds,
                $this->categoryDescendantIds(
                    (int) $item->target_id,
                    MenuCategoryExpansion::level($item->custom),
                ),
            );
        }

        return $this->targetCategoryIdsMemo[$memoKey] = array_values(
            array_unique(array_merge($rootIds, $descendantIds))
        );
    }

    /** @return array<int, int> */
    private function categoryDescendantIds(int $rootId, int $maxDepth): array
    {
        $visited = [$rootId => true];
        $frontier = [$rootId];
        $descendantIds = [];
        $depth = 0;

        while ($frontier !== [] && ($maxDepth === 0 || $depth < $maxDepth)) {
            $children = Category::query()
                ->withoutGlobalScope(self::CATEGORY_APPENDS_SCOPE)
                ->withTrashed()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter()
                ->values()
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }

                $visited[$childId] = true;
                $descendantIds[] = $childId;
                $frontier[] = $childId;
            }

            $depth++;
        }

        return $descendantIds;
    }

    private function fingerprint(string $table, mixed $query, array $candidateColumns): array
    {
        $count = (clone $query)->count();

        $fingerprint = [
            'table' => $table,
            'exists' => true,
            'count' => $count,
            'max_id' => $this->columnExists($table, 'id') ? (clone $query)->max('id') : null,
            'max_updated_at' => $this->columnExists($table, 'updated_at')
                ? $this->dateValue((clone $query)->max('updated_at'))
                : null,
            'max_deleted_at' => $this->columnExists($table, 'deleted_at')
                ? $this->dateValue((clone $query)->max('deleted_at'))
                : null,
        ];

        $rowDigest = $this->rowDigest($table, $query, $count, $candidateColumns);
        if ($rowDigest !== null) {
            $fingerprint['row_digest'] = $rowDigest;
        }

        return $fingerprint;
    }

    private function rowDigest(string $table, mixed $query, int $count, array $candidateColumns): ?string
    {
        if ($count < 1 || $count > self::ROW_DIGEST_LIMIT) {
            return null;
        }

        $columns = array_values(array_intersect($candidateColumns, $this->columnListing($table)));

        if ($columns === []) {
            return null;
        }

        $rows = (clone $query)
            ->select($columns)
            ->orderBy(in_array('id', $columns, true) ? 'id' : $columns[0])
            ->get()
            ->map(function ($row): array {
                return $row instanceof Model ? $row->getAttributes() : (array) $row;
            })
            ->map(fn (array $row): array => $this->normalizeRow($row))
            ->values()
            ->all();

        return $this->hash([
            'columns' => $columns,
            'rows' => $rows,
        ]);
    }

    private function normalizeRow(array $row): array
    {
        ksort($row);

        foreach ($row as $key => $value) {
            $row[$key] = $this->normalizeForHash($value);
        }

        return $row;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $this->dateValue($value);
        }

        if ($value instanceof EloquentCollection) {
            return $this->normalizeForHash($value->values()->all());
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForHash($item);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return $value === null ? null : (string) $value;
    }
}
