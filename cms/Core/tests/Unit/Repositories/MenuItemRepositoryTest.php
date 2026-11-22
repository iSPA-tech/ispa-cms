<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Tests\Support\AppendedRelationSchema;
use Cms\Core\Constants\CoreTableConstant;
use App\Models\Category as LegacyCategory;
use App\Models\Slug;
use Cms\Core\Http\Resources\Website\PublicMenuItemResource;
use Cms\Core\Models\Category;
use Cms\Core\Models\MenuItem;
use Cms\Core\Repositories\MenuItemRepository;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Cms\Core\Services\Menu\MenuItemUrlResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('MenuItemRepository getMenuItemListByMenuGroup builds public menu query', function () {
    Cache::flush();

    $repo = new class extends MenuItemRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $repo->getMenuItemListByMenuGroup('main');

    expect($repo->lastGet['select'])->toContain('id', 'menu_id', 'name', 'title');
    expect($repo->lastGet['where'])->toBe(['parent_id' => null]);
    expect($repo->lastGet['order'])->toBe(['priority' => 'asc']);
    expect($repo->lastGet['has'])->toHaveKey('menu');
    expect($repo->lastGet['when'][0][0])->toBe("type='url'");
    expect($repo->lastGet['when'][1][0])->toBe("type != 'url' && target_id is not null");
});

test('MenuItemRepository exposes category expansion level to the shared renderer', function () {
    Cache::flush();

    $target = new Category(['name' => 'Danh mục A']);
    $target->id = 10;

    $item = new MenuItem([
        'type' => 'category',
        'custom' => [
            'isExpandChild' => true,
            'expandChildLevel' => 1,
        ],
    ]);
    $item->setRelation('target', $target);
    $item->setRelation('items', collect());

    $repo = new class($item) extends MenuItemRepository {
        public function __construct(private MenuItem $item) {}

        public function get(array $params): mixed
        {
            return collect([$this->item]);
        }
    };

    $result = $repo->getMenuItemListByMenuGroup('main_menu');

    expect($result->first()->target->menu_expand_child_level)->toBe(1);
});

test('public menu resolver uses target category slug instead of stale menu item url', function () {
    $category = new Category([
        'name' => 'Suy niệm - Chia sẻ',
        'type' => 0,
        'status' => 'status_published',
    ]);
    $category->setRelation('latestSlug', new Slug([
        'slug' => 'suy-niem-chia-se',
    ]));

    $menu = new MenuItem([
        'name' => 'Suy niệm',
        'url' => 'bai-viet/suy-niemchia-se',
        'type' => 'category',
        'target_type' => Category::class,
        'target_id' => 10,
    ]);
    $menu->setRelation('target', $category);

    $url = app(MenuItemUrlResolver::class)->resolve($menu);

    expect($url)
        ->toContain('suy-niem-chia-se')
        ->not->toContain('suy-niemchia-se');
});

test('public menu resolver treats a null custom prefix as an empty path', function () {
    $menu = new MenuItem([
        'name' => 'About',
        'url' => '/about',
        'type' => 'url',
        'custom' => ['prefix' => null],
    ]);

    expect(app(MenuItemUrlResolver::class)->resolve($menu))
        ->toBe(url('about'));
});

test('public menu resource exposes canonical target url for category menu items', function () {
    $category = new Category([
        'name' => 'Suy niệm - Chia sẻ',
        'type' => 0,
        'status' => 'status_published',
    ]);
    $category->setRelation('latestSlug', new Slug([
        'slug' => 'suy-niem-chia-se',
    ]));

    $menu = new MenuItem([
        'id' => 1,
        'name' => 'Suy niệm',
        'title' => 'Suy niệm',
        'url' => 'bai-viet/suy-niemchia-se',
        'type' => 'category',
        'target_type' => Category::class,
        'target_id' => 10,
        'parent_id' => null,
        'custom' => [],
    ]);
    $menu->setRelation('target', $category);
    $menu->setRelation('items', collect());

    $payload = (new PublicMenuItemResource($menu))->toArray(request());

    expect($payload['url'])
        ->toContain('suy-niem-chia-se')
        ->not->toContain('suy-niemchia-se');
});

test('MenuItemRepository menu group cache moves when menu item rows change', function () {
    Cache::flush();
    //  These cases assert the fingerprint contract itself, so the short-lived
    //  memo in front of it is switched off: with it on, a second read inside
    //  the window is answered from the previous key by design.
    config(['core.public_menu.fingerprint_ttl' => 0]);
    createMenuRepositoryCacheTables();

    $languageId = lang('id', get_lang_id());

    DB::table(CoreTableConstant::MENUS)->insert([
        'id' => 1,
        'group_key' => 'main_menu',
        'name' => 'Main menu',
        'language_id' => $languageId,
        'status' => 'status_published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table(CoreTableConstant::MENU_ITEMS)->insert([
        'id' => 1,
        'menu_id' => 1,
        'name' => 'home',
        'title' => 'Old title',
        'url' => '/',
        'type' => 'url',
        'target_type' => null,
        'target_id' => null,
        'parent_id' => null,
        'priority' => 0,
        'custom' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $repo = new MenuItemRepository();

    expect($repo->getMenuItemListByMenuGroup('main_menu')->first()->title)->toBe('Old title');

    DB::table(CoreTableConstant::MENU_ITEMS)
        ->where('id', 1)
        ->update([
            'title' => 'New title',
            'updated_at' => now()->addSecond(),
        ]);

    expect($repo->getMenuItemListByMenuGroup('main_menu')->first()->title)->toBe('New title');

    DB::table(CoreTableConstant::MENU_ITEMS)
        ->where('id', 1)
        ->update([
            'deleted_at' => now()->addSeconds(2),
            'updated_at' => now()->addSeconds(2),
        ]);

    expect($repo->getMenuItemListByMenuGroup('main_menu'))->toHaveCount(0);
});

test('Core public menu cache key moves when targeted category tree changes', function () {
    Cache::flush();
    //  These cases assert the fingerprint contract itself, so the short-lived
    //  memo in front of it is switched off: with it on, a second read inside
    //  the window is answered from the previous key by design.
    config(['core.public_menu.fingerprint_ttl' => 0]);
    createMenuRepositoryCacheTables();
    createMenuRepositoryCategoryTables();

    $languageId = lang('id', get_lang_id());
    $now = now();

    DB::table(CoreTableConstant::MENUS)->insert([
        'id' => 1,
        'group_key' => 'main_menu',
        'name' => 'Main menu',
        'language_id' => $languageId,
        'status' => 'status_published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table(CoreTableConstant::CATEGORIES)->insert([
        [
            'id' => 10,
            'uid' => 'cat000001',
            'name' => 'Parent category',
            'description' => null,
            'content' => null,
            'hashtag' => null,
            'parent_id' => null,
            'language_id' => $languageId,
            'priority' => 0,
            'type' => 0,
            'featured' => false,
            'status' => 'status_published',
            'config' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => 11,
            'uid' => 'cat000002',
            'name' => 'Old child category',
            'description' => null,
            'content' => null,
            'hashtag' => null,
            'parent_id' => 10,
            'language_id' => $languageId,
            'priority' => 0,
            'type' => 0,
            'featured' => false,
            'status' => 'status_published',
            'config' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table(CoreTableConstant::MENU_ITEMS)->insert([
        'id' => 1,
        'menu_id' => 1,
        'name' => 'Category link',
        'title' => 'Category link',
        'url' => 'old-category',
        'type' => 'category',
        'target_type' => Category::class,
        'target_id' => 10,
        'parent_id' => null,
        'priority' => 0,
        'custom' => json_encode(['isExpandChild' => true], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cache = new CorePublicMenuCacheService();
    $oldKey = $cache->groupItemsKey('main_menu', $languageId);

    DB::table(CoreTableConstant::CATEGORIES)
        ->where('id', 11)
        ->update([
            'name' => 'New child category',
            'updated_at' => $now->copy()->addSecond(),
        ]);

    expect($cache->groupItemsKey('main_menu', $languageId))->not->toBe($oldKey);
});

test('Core public menu cache fingerprints only configured category descendant levels', function () {
    Cache::flush();
    config(['core.public_menu.fingerprint_ttl' => 0]);
    createMenuRepositoryCacheTables();
    createMenuRepositoryCategoryTables();

    $languageId = lang('id', get_lang_id());
    $now = now();

    DB::table(CoreTableConstant::MENUS)->insert([
        'id' => 1,
        'group_key' => 'main_menu',
        'name' => 'Main menu',
        'language_id' => $languageId,
        'status' => 'status_published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table(CoreTableConstant::CATEGORIES)->insert(collect(range(10, 13))->map(
        static fn (int $id, int $index): array => [
            'id' => $id,
            'uid' => 'cat' . str_pad((string) $id, 7, '0', STR_PAD_LEFT),
            'name' => 'Category ' . $id,
            'description' => null,
            'content' => null,
            'hashtag' => null,
            'parent_id' => $index === 0 ? null : $id - 1,
            'language_id' => $languageId,
            'priority' => 0,
            'type' => 0,
            'featured' => false,
            'status' => 'status_published',
            'config' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    )->all());

    DB::table(CoreTableConstant::MENU_ITEMS)->insert([
        'id' => 1,
        'menu_id' => 1,
        'name' => 'Category link',
        'title' => 'Category link',
        'url' => 'category-10',
        'type' => 'category',
        'target_type' => Category::class,
        'target_id' => 10,
        'parent_id' => null,
        'priority' => 0,
        'custom' => json_encode([
            'isExpandChild' => true,
            'expandChildLevel' => 1,
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cache = new CorePublicMenuCacheService();
    $limitedKey = $cache->groupItemsKey('main_menu', $languageId);

    DB::table(CoreTableConstant::CATEGORIES)->where('id', 12)->update([
        'name' => 'Changed outside level one',
        'updated_at' => $now->copy()->addSecond(),
    ]);

    expect($cache->groupItemsKey('main_menu', $languageId))->toBe($limitedKey);

    DB::table(CoreTableConstant::MENU_ITEMS)->where('id', 1)->update([
        'custom' => json_encode([
            'isExpandChild' => true,
            'expandChildLevel' => 0,
        ], JSON_THROW_ON_ERROR),
        'updated_at' => $now->copy()->addSeconds(2),
    ]);

    $cache = new CorePublicMenuCacheService();
    $allLevelsKey = $cache->groupItemsKey('main_menu', $languageId);

    DB::table(CoreTableConstant::CATEGORIES)->where('id', 13)->update([
        'name' => 'Changed in all levels',
        'updated_at' => $now->copy()->addSeconds(3),
    ]);

    expect($cache->groupItemsKey('main_menu', $languageId))->not->toBe($allLevelsKey);
});

test('Core public menu cache key moves when legacy category slug rows change', function () {
    Cache::flush();
    //  These cases assert the fingerprint contract itself, so the short-lived
    //  memo in front of it is switched off: with it on, a second read inside
    //  the window is answered from the previous key by design.
    config(['core.public_menu.fingerprint_ttl' => 0]);
    createMenuRepositoryCacheTables();
    createMenuRepositoryCategoryTables();
    createMenuRepositorySlugTables();

    $languageId = lang('id', get_lang_id());
    $now = now();

    DB::table(CoreTableConstant::MENUS)->insert([
        'id' => 1,
        'group_key' => 'main_menu',
        'name' => 'Main menu',
        'language_id' => $languageId,
        'status' => 'status_published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table(CoreTableConstant::CATEGORIES)->insert([
        'id' => 10,
        'uid' => 'cat000001',
        'name' => 'Category',
        'description' => null,
        'content' => null,
        'hashtag' => null,
        'parent_id' => null,
        'language_id' => $languageId,
        'priority' => 0,
        'type' => 0,
        'featured' => false,
        'status' => 'status_published',
        'config' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table(CoreTableConstant::MENU_ITEMS)->insert([
        'id' => 1,
        'menu_id' => 1,
        'name' => 'Category link',
        'title' => 'Category link',
        'url' => 'bai-viet/suy-niemchia-se',
        'type' => 'category',
        'target_type' => Category::class,
        'target_id' => 10,
        'parent_id' => null,
        'priority' => 0,
        'custom' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('slugs')->insert([
        'id' => 1,
        'full_uri' => 'bai-viet/suy-niemchia-se',
        'slug' => 'suy-niemchia-se',
        'category' => null,
        'target_type' => LegacyCategory::class,
        'target_id' => 10,
        'language_id' => $languageId,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);

    $cache = new CorePublicMenuCacheService();
    $oldKey = $cache->groupItemsKey('main_menu', $languageId);

    DB::table('slugs')
        ->where('id', 1)
        ->update([
            'full_uri' => 'bai-viet/suy-niem-chia-se',
            'slug' => 'suy-niem-chia-se',
            'updated_at' => $now->copy()->addSecond(),
        ]);

    expect($cache->groupItemsKey('main_menu', $languageId))->not->toBe($oldKey);
});

function createMenuRepositoryCacheTables(): void
{
    Schema::dropIfExists(CoreTableConstant::MENU_ITEMS);
    Schema::dropIfExists(CoreTableConstant::MENUS);

    Schema::create(CoreTableConstant::MENUS, static function (Blueprint $table): void {
        $table->id();
        $table->string('group_key')->nullable();
        $table->string('name')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create(CoreTableConstant::MENU_ITEMS, static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('menu_id')->nullable();
        $table->string('name')->nullable();
        $table->string('title')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->nullableMorphs('target');
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->integer('priority')->default(0);
        $table->json('custom')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function createMenuRepositorySlugTables(): void
{
    Schema::dropIfExists('slugs');

    Schema::create('slugs', static function (Blueprint $table): void {
        $table->id();
        $table->string('full_uri')->nullable();
        $table->string('slug')->nullable();
        $table->string('category')->nullable();
        $table->nullableMorphs('target');
        $table->unsignedBigInteger('language_id')->nullable();
        $table->timestamp('index_now_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function createMenuRepositoryCategoryTables(): void
{
    //  The Category global scope reads slugs + uploads on every query.
    AppendedRelationSchema::ensure();

    Schema::dropIfExists(CoreTableConstant::CATEGORIES);

    Schema::create(CoreTableConstant::CATEGORIES, static function (Blueprint $table): void {
        $table->id();
        $table->char('uid', 10)->nullable()->unique();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->longText('content')->nullable();
        $table->json('hashtag')->nullable();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->unsignedTinyInteger('priority')->default(0);
        $table->unsignedTinyInteger('type')->default(0);
        $table->boolean('featured')->default(false);
        $table->string('status')->nullable();
        $table->json('config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('Core public menu cache key memo holds inside the window and clears on demand', function () {
    Cache::flush();
    config(['core.public_menu.fingerprint_ttl' => 60]);
    createMenuRepositoryCacheTables();

    $languageId = lang('id', get_lang_id());
    $now = now();

    DB::table(CoreTableConstant::MENUS)->insert([
        'id' => 1,
        'group_key' => 'main_menu',
        'name' => 'Main menu',
        'language_id' => $languageId,
        'status' => 'status_published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table(CoreTableConstant::MENU_ITEMS)->insert([
        'id' => 1,
        'menu_id' => 1,
        'name' => 'home',
        'title' => 'Old title',
        'url' => '/',
        'type' => 'url',
        'parent_id' => null,
        'priority' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cache = new CorePublicMenuCacheService();
    $original = $cache->groupItemsKey('main_menu', $languageId);

    DB::table(CoreTableConstant::MENU_ITEMS)
        ->where('id', 1)
        ->update(['title' => 'New title', 'updated_at' => $now->copy()->addSecond()]);

    //  Inside the window the memo still answers with the previous key — that is
    //  what buys back the ~45 queries the fingerprint costs.
    expect($cache->groupItemsKey('main_menu', $languageId))->toBe($original);

    //  A menu save calls this, so an edit made through the CMS is never stale.
    $cache->forgetGroupItemsKey('main_menu', $languageId);

    expect($cache->groupItemsKey('main_menu', $languageId))->not->toBe($original);
});
