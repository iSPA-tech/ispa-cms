<?php

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    session([
        'language-locale-vi' => (object) [],
        'language-locales' => [],
        'current-language' => (object) [],
    ]);
});

function menuCategoryNode(int $id, string $name, array $children = []): object
{
    return (object) [
        'id' => $id,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'route' => '/' . str($name)->slug(),
        'has_children' => $children !== [],
        'public_children' => collect($children),
    ];
}

test('category menu renderer respects configured child levels', function (int $level, array $visible, array $hidden) {
    $greatGrandchild = menuCategoryNode(4, 'Danh mục G');
    $grandchild = menuCategoryNode(3, 'Danh mục E', [$greatGrandchild]);
    $child = menuCategoryNode(2, 'Danh mục B', [$grandchild]);
    $root = menuCategoryNode(1, 'Danh mục A', [$child]);
    $root->menu_expand_child_level = $level;

    $html = view('components.tools.category-multiple-level', ['item' => $root])->render();

    foreach ($visible as $name) {
        expect($html)->toContain($name);
    }
    foreach ($hidden as $name) {
        expect($html)->not->toContain($name);
    }
})->with([
    'one level' => [1, ['Danh mục B'], ['Danh mục E', 'Danh mục G']],
    'two levels' => [2, ['Danh mục B', 'Danh mục E'], ['Danh mục G']],
    'all levels' => [0, ['Danh mục B', 'Danh mục E', 'Danh mục G'], []],
]);

test('category menu renderer stops malformed parent cycles', function () {
    $root = menuCategoryNode(1, 'Danh mục A');
    $child = menuCategoryNode(2, 'Danh mục B', [$root]);
    $root->has_children = true;
    $root->public_children = collect([$child]);
    $root->menu_expand_child_level = 0;

    $html = view('components.tools.category-multiple-level', ['item' => $root])->render();

    expect($html)
        ->toContain('Danh mục B')
        ->not->toContain('>Danh mục A</a>');
});
