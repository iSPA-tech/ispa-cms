<?php

declare(strict_types=1);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\PublicMenuInvalidated;
use Cms\Core\Models\Enumerate;
use Cms\Core\Models\Language;
use Cms\Core\Models\Menu;
use Cms\Core\Models\MenuItem;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\MenusSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    // The audit log table is outside this test's schema; observers still run, only the audit event is faked.
    Event::fake([AuditLogEvent::class]);

    foreach (['menu_items', 'menus', 'enumerates', 'languages'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('languages', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->string('path')->unique();
        $table->string('path_alias')->nullable();
        $table->string('code')->nullable();
        $table->string('flag')->nullable();
        $table->string('time_zone')->nullable();
        $table->boolean('default_web')->default(false);
        $table->boolean('default_adm')->default(false);
        $table->boolean('default_app')->default(false);
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->string('status')->nullable();
        $table->boolean('is_locked')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('enumerates', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('name_alias')->nullable();
        $table->string('enum_key')->nullable();
        $table->string('type')->nullable();
        $table->unsignedBigInteger('type_id')->nullable();
        $table->boolean('is_filter')->default(false);
        $table->boolean('status')->default(true);
        $table->string('input_type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('menus', function (Blueprint $table) {
        $table->id();
        $table->char('group_key', 20)->nullable();
        $table->string('name', 255)->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->char('status', 50)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('menu_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('menu_id')->nullable();
        $table->string('name')->nullable();
        $table->string('title')->nullable();
        $table->string('url', 500)->nullable();
        $table->char('type', 30)->nullable();
        $table->nullableMorphs('target');
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->smallInteger('priority')->default(0);
        $table->text('custom')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Enumerate::create(['name' => 'Published', 'enum_key' => 'status_published']);
    Enumerate::create(['name' => 'Draft', 'enum_key' => 'status_draft']);
});

/**
 * Create a language and return it.
 */
function mnLang(string $path): Language
{
    return Language::forceCreate(['name' => strtoupper($path), 'path' => $path, 'code' => $path]);
}

/**
 * Create a menu.
 *
 * @param array<string, mixed> $overrides
 */
function mnMenu(string $name, ?Language $language = null, array $overrides = []): Menu
{
    return Menu::create(array_merge([
        'group_key' => 'main_menu',
        'name' => $name,
        'language_id' => $language?->id,
        'status' => 'status_published',
    ], $overrides));
}

/**
 * Create a menu item.
 *
 * @param array<string, mixed> $overrides
 */
function mnItem(Menu $menu, string $name, ?MenuItem $parent = null, int $priority = 0, array $overrides = []): MenuItem
{
    return MenuItem::create(array_merge([
        'menu_id' => $menu->id,
        'name' => $name,
        'title' => $name . ' title',
        'url' => '/' . strtolower($name),
        'type' => 'url',
        'parent_id' => $parent?->id,
        'priority' => $priority,
        'custom' => ['icon' => null, 'categoryId' => []],
    ], $overrides));
}

/**
 * Recursively collect every array key of a value.
 *
 * @return list<string>
 */
function mnKeys(mixed $value): array
{
    $keys = [];
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            array_push($keys, ...mnKeys($v));
        }
    }

    return $keys;
}

/**
 * Sorted `parent>name@priority` labels of the current items of a menu.
 *
 * @return list<string>
 */
function mnTree(Menu $menu): array
{
    $items = MenuItem::query()->where('menu_id', $menu->id)->orderBy('priority')->orderBy('id')->get();
    $names = $items->pluck('name', 'id');

    $labels = $items->map(fn (MenuItem $i): string => ($i->parent_id ? $names[$i->parent_id] . '>' : '') . $i->name . '@' . $i->priority)->all();
    sort($labels);

    return $labels;
}

/**
 * One incoming row.
 *
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function mnRow(string $name, array $items = [], array $overrides = []): array
{
    return array_merge([
        'group_key' => 'main_menu',
        'language' => 'vi',
        'name' => $name,
        'status' => 'status_published',
        'items' => $items,
    ], $overrides);
}

/**
 * One incoming item.
 *
 * @param list<array<string, mixed>> $children
 *
 * @return array<string, mixed>
 */
function mnIn(string $name, array $children = []): array
{
    return ['name' => $name, 'title' => $name . ' title', 'url' => '/' . strtolower($name), 'type' => 'url', 'custom' => [], 'items' => $children];
}

it('exports a nested tree by stable names with the language path and no numeric ids', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi);
    $shop = mnItem($menu, 'Shop', null, 0);
    mnItem($menu, 'Shoes', $shop, 0);
    mnItem($menu, 'About', null, 1, ['custom' => ['icon' => 'x', 'categoryId' => []]]);

    $export = (new MenusSection())->export(new ExportContext());

    expect($export->skipped)->toBe([])
        ->and($export->rows)->toHaveCount(1)
        ->and($export->rows[0]['group_key'])->toBe('main_menu')
        ->and($export->rows[0]['language'])->toBe('vi')
        ->and($export->rows[0]['name'])->toBe('Header')
        ->and(array_column($export->rows[0]['items'], 'name'))->toBe(['Shop', 'About'])
        ->and($export->rows[0]['items'][0]['items'][0]['name'])->toBe('Shoes')
        ->and($export->rows[0]['items'][1]['custom'])->toBe(['icon' => 'x']);

    $keys = mnKeys($export->toArray());
    foreach (['id', 'menu_id', 'parent_id', 'language_id', 'target_id', 'target_type', 'categoryId', 'priority'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('skips items pointing at records and their descendants and lists them by name', function (): void {
    $menu = mnMenu('Header');
    mnItem($menu, 'Home', null, 0);
    $post = mnItem($menu, 'Linked post', null, 1, ['target_type' => 'posts', 'target_id' => 7]);
    mnItem($menu, 'Child of linked', $post, 0);
    $cat = mnItem($menu, 'Category', null, 2, ['type' => 'category', 'custom' => ['categoryId' => [3, 4]]]);
    mnItem($menu, 'Child of category', $cat, 0);
    mnItem($menu, 'Keep', null, 3);

    $export = (new MenusSection())->export(new ExportContext());
    $row = $export->rows[0];

    expect(array_column($row['items'], 'name'))->toBe(['Home', 'Keep'])
        ->and($export->skipped)->toBe(['parent_skipped' => 2, 'points_at_record' => 2])
        ->and($row['language'])->toBeNull()
        ->and($row['skipped_items'])->toBe([
            ['name' => 'Linked post', 'reason' => 'points_at_record'],
            ['name' => 'Child of linked', 'reason' => 'parent_skipped'],
            ['name' => 'Category', 'reason' => 'points_at_record'],
            ['name' => 'Child of category', 'reason' => 'parent_skipped'],
        ]);
});

it('does not export soft-deleted items and skips duplicate menu keys as ambiguous', function (): void {
    $menu = mnMenu('Header');
    mnItem($menu, 'Gone')->delete();
    mnMenu('Twin');
    mnMenu('Twin');

    $export = (new MenusSection())->export(new ExportContext());

    expect(array_column($export->rows, 'name'))->toBe(['Header'])
        ->and($export->rows[0]['items'])->toBe([])
        ->and($export->skipped)->toBe(['ambiguous' => 2]);
});

it('diffs a new menu as create and an unchanged one as unchanged', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi);
    mnItem($menu, 'Home');

    $diff = (new MenusSection())->diff([
        mnRow('Footer', [mnIn('Contact')]),
        mnRow('Header', [mnIn('Home')]),
    ], new DiffContext());

    expect($diff->item('main_menu|vi|Footer')['action'])->toBe('create')
        ->and($diff->item('main_menu|vi|Footer')['items_to_add'])->toBe(1)
        ->and($diff->item('main_menu|vi|Header')['action'])->toBe('unchanged')
        ->and(json_encode($diff->toArray()))->not->toContain('menu_id');
});

it('matches a menu by group key, language path and name only', function (): void {
    $vi = mnLang('vi');
    $en = mnLang('en');
    mnMenu('Header', $en);
    mnMenu('Header', $vi, ['group_key' => 'footer_menu']);

    $diff = (new MenusSection())->diff([mnRow('Header')], new DiffContext());

    expect($diff->item('main_menu|vi|Header')['action'])->toBe('create');

    mnMenu('Header', $vi);
    $diff = (new MenusSection())->diff([mnRow('Header')], new DiffContext());

    expect($diff->item('main_menu|vi|Header')['action'])->toBe('unchanged')
        ->and($diff->item('main_menu|vi|Header')['before']['items'])->toBe(0);
});

it('offers one off-by-default replace_items choice with the number of target items it would remove', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi);
    $a = mnItem($menu, 'A');
    mnItem($menu, 'A1', $a);
    mnItem($menu, 'B', null, 1);

    $diff = (new MenusSection())->diff([mnRow('Header', [mnIn('A'), mnIn('C')])], new DiffContext());
    $item = $diff->item('main_menu|vi|Header');

    expect($item['action'])->toBe('update')
        ->and($item['items_to_add'])->toBe(1)
        ->and($item['choices'])->toHaveCount(1)
        ->and($item['choices'][0])->toMatchArray([
            'key' => 'main_menu|vi|Header#replace_items',
            'name' => 'replace_items',
            'default' => false,
            'removes' => 3,
            'adds' => 2,
        ]);
});

it('blocks a menu whose status or language does not exist on the target', function (): void {
    mnLang('vi');

    $diff = (new MenusSection())->diff([
        mnRow('A', [], ['status' => 'status_unknown']),
        mnRow('B', [], ['language' => 'zz']),
        mnRow('C', [mnIn('x'), ['title' => 'no name']]),
    ], new DiffContext());

    expect($diff->item('main_menu|vi|A')['action'])->toBe('blocked')
        ->and($diff->item('main_menu|vi|A')['reason'])->toContain('status_unknown')
        ->and($diff->item('main_menu|zz|B')['action'])->toBe('blocked')
        ->and($diff->item('main_menu|vi|C')['action'])->toBe('blocked');
});

it('blocks ambiguous menus on the target and duplicated menus in the package', function (): void {
    $vi = mnLang('vi');
    mnMenu('Twin', $vi);
    mnMenu('Twin', $vi);

    $diff = (new MenusSection())->diff([mnRow('Twin'), mnRow('Dup'), mnRow('Dup')], new DiffContext());

    expect($diff->item('main_menu|vi|Twin')['action'])->toBe('blocked')
        ->and($diff->item('main_menu|vi|Dup')['action'])->toBe('blocked');
});

it('creates a new menu with its nested items through the models and fires observers', function (): void {
    $vi = mnLang('vi');
    $created = [];
    Event::listen('eloquent.created: ' . Menu::class, function () use (&$created): void {
        $created[] = 'menu';
    });
    Event::listen('eloquent.created: ' . MenuItem::class, function () use (&$created): void {
        $created[] = 'item';
    });

    $result = (new MenusSection())->apply(
        [mnRow('Header', [mnIn('Shop', [mnIn('Shoes'), mnIn('Hats')]), mnIn('About')])],
        new ApplyContext(),
    );

    $menu = Menu::query()->firstOrFail();
    expect($result->updated)->toBe(1)
        ->and($result->items[0])->toMatchArray(['outcome' => 'created', 'added_items' => 4, 'removed_items' => 0])
        ->and($menu->language_id)->toBe($vi->id)
        ->and($menu->status)->toBe('status_published')
        ->and(mnTree($menu))->toBe(['About@1', 'Shop>Hats@1', 'Shop>Shoes@0', 'Shop@0'])
        ->and($created)->toBe(['menu', 'item', 'item', 'item', 'item']);

    $item = MenuItem::query()->where('name', 'Shoes')->firstOrFail();
    expect($item->target_id)->toBeNull()->and($item->url)->toBe('/shoes');
});

it('keeps target items and adds only missing ones under the matched parent without the choice', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi, ['status' => 'status_draft']);
    $shop = mnItem($menu, 'Shop', null, 0, ['title' => 'Local title']);
    mnItem($menu, 'Local only', null, 1);
    mnItem($menu, 'Linked', null, 2, ['target_type' => 'posts', 'target_id' => 9]);

    $result = (new MenusSection())->apply(
        [mnRow('Header', [mnIn('Shop', [mnIn('Shoes')]), mnIn('News')])],
        new ApplyContext(),
    );

    expect($result->items[0])->toMatchArray(['outcome' => 'updated', 'added_items' => 2, 'removed_items' => 0])
        ->and($menu->fresh()->status)->toBe('status_published')
        ->and(MenuItem::query()->where('name', 'Shop')->firstOrFail()->title)->toBe('Local title')
        ->and(MenuItem::query()->where('name', 'Shoes')->firstOrFail()->parent_id)->toBe($shop->id)
        ->and(mnTree($menu))->toBe(['Linked@2', 'Local only@1', 'News@3', 'Shop>Shoes@0', 'Shop@0'])
        ->and(MenuItem::query()->where('name', 'Linked')->firstOrFail()->target_id)->toBe(9);
});

it('matches duplicate sibling names by their order and a second apply changes nothing', function (): void {
    mnLang('vi');
    $rows = [mnRow('Header', [mnIn('Dup'), mnIn('Dup'), mnIn('Dup')])];
    $section = new MenusSection();

    $first = $section->apply($rows, new ApplyContext());
    $second = $section->apply($rows, new ApplyContext());

    expect($first->items[0]['added_items'])->toBe(3)
        ->and($second->unchanged)->toBe(1)
        ->and(MenuItem::query()->count())->toBe(3);
});

it('replaces items only when the replace_items choice is on, soft-deleting the old ones', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi);
    $old = mnItem($menu, 'Old');
    mnItem($menu, 'Old child', $old);
    mnItem($menu, 'Linked', null, 1, ['target_type' => 'posts', 'target_id' => 9]);
    $other = mnMenu('Other', $vi);
    mnItem($other, 'Untouched');

    $rows = [mnRow('Header', [mnIn('New B'), mnIn('New A', [mnIn('Kid')])])];

    $off = (new MenusSection())->apply($rows, new ApplyContext(['main_menu|vi|Header#replace_items' => false]));
    expect($off->items[0]['removed_items'])->toBe(0)
        ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(6);

    $result = (new MenusSection())->apply($rows, new ApplyContext(['main_menu|vi|Header#replace_items' => true]));

    expect($result->items[0])->toMatchArray(['outcome' => 'updated', 'added_items' => 3, 'removed_items' => 6])
        ->and(mnTree($menu))->toBe(['New A>Kid@0', 'New A@1', 'New B@0'])
        ->and(MenuItem::onlyTrashed()->where('menu_id', $menu->id)->count())->toBe(6)
        ->and(MenuItem::query()->where('menu_id', $other->id)->pluck('name')->all())->toBe(['Untouched']);
});

it('skips a menu that the operator deselected and never applies blocked ones', function (): void {
    mnLang('vi');

    $result = (new MenusSection())->apply(
        [mnRow('Off', [mnIn('x')]), mnRow('Bad', [], ['status' => 'nope'])],
        new ApplyContext(['main_menu|vi|Off' => false]),
    );

    expect($result->skipped)->toBe(1)
        ->and($result->blocked)->toBe(1)
        ->and(Menu::query()->count())->toBe(0);
});

it('round-trips: export A, change to B, import restores A keys and leaves other rows alone', function (): void {
    $vi = mnLang('vi');
    $menu = mnMenu('Header', $vi);
    $shop = mnItem($menu, 'Shop', null, 0, ['custom' => ['icon' => 'bag']]);
    mnItem($menu, 'Shoes', $shop);
    mnItem($menu, 'About', null, 1);
    mnItem($menu, 'Linked', null, 2, ['target_type' => 'posts', 'target_id' => 5]);
    $section = new MenusSection();

    $exportA = $section->export(new ExportContext());
    $packageA = json_decode(json_encode($exportA->rows), true);

    // State B: drop an item, change the status, add a local item and an unrelated menu.
    MenuItem::query()->where('name', 'Shoes')->firstOrFail()->delete();
    $menu->update(['status' => 'status_draft']);
    mnItem($menu, 'Local', null, 5);
    $unrelated = mnMenu('Unrelated', $vi, ['group_key' => 'footer_menu']);
    mnItem($unrelated, 'Stay');

    $section->apply($packageA, new ApplyContext());

    $keysAfter = array_map(fn (array $r): array => ['name' => $r['name'], 'items' => $r['items']], $section->export(new ExportContext())->rows);
    $header = collect($keysAfter)->firstWhere('name', 'Header');

    expect($menu->fresh()->status)->toBe('status_published')
        ->and(mnKeys($header['items']))->not->toContain('id')
        ->and(collect($header['items'])->pluck('name')->sort()->values()->all())->toBe(['About', 'Local', 'Shop'])
        ->and(collect($header['items'])->firstWhere('name', 'Shop')['items'][0]['name'])->toBe('Shoes')
        ->and(collect($header['items'])->firstWhere('name', 'Shop')['custom'])->toBe(['icon' => 'bag'])
        ->and(MenuItem::query()->where('name', 'Linked')->firstOrFail()->target_id)->toBe(5)
        ->and(MenuItem::query()->where('menu_id', $unrelated->id)->pluck('name')->all())->toBe(['Stay'])
        ->and(Menu::query()->where('name', 'Unrelated')->firstOrFail()->status)->toBe('status_published');
});

it('afterApply invalidates the menu caches of every changed group through the controller service', function (): void {
    $vi = mnLang('vi');
    Event::fake([AuditLogEvent::class, PublicMenuInvalidated::class]);
    $section = new MenusSection();

    $section->apply([
        mnRow('Header', [mnIn('a')]),
        mnRow('Foot', [mnIn('b')], ['group_key' => 'footer_menu']),
    ], new ApplyContext());
    $section->afterApply(new ApplyContext());

    Event::assertDispatchedTimes(PublicMenuInvalidated::class, 2);
    Event::assertDispatched(PublicMenuInvalidated::class, fn ($e): bool => $e->groupKey === 'main_menu' && $e->languageId === $vi->id);
    Event::assertDispatched(PublicMenuInvalidated::class, fn ($e): bool => $e->groupKey === 'footer_menu');

    // A fresh instance has no record of the apply and clears every group.
    (new MenusSection())->afterApply(new ApplyContext());
    Event::assertDispatchedTimes(PublicMenuInvalidated::class, 4);
});
