<?php

declare(strict_types=1);

use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Interfaces\MenuItemInterface;
use Cms\Core\Models\Category;
use Cms\Core\Models\Menu;
use Cms\Core\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Default theme: a shared layout whose menus come
 * from the menus table (main_menu in the header, footer_menu in the footer) and a home that lists the site's own posts.
 * Repositories are mocked: the menus and posts they return are what the theme must render.
 */

beforeEach(function () {
    if (config('ispa-cms.folder_name') !== 'Default') {
        $this->markTestSkipped('Run with VITE_TEMPLATE_FOLDER=Default VITE_TEMPLATE_VIEWS_PATH=projects/Default/views/.');
    }
    $this->withoutVite();
});

/** A menu item of type url, with optional children. */
function dtItem(string $name, string $url, array $children = []): MenuItem
{
    $item = new MenuItem(['name' => $name, 'title' => $name, 'type' => 'url', 'url' => $url]);
    $item->setRelation('items', collect($children));

    return $item;
}

/** A published post with a slug, an optional category and no image. */
function dtPost(string $name, string $slug, ?string $category = null): Post
{
    $post = new Post();
    $post->forceFill(['name' => $name, 'description' => 'Desc ' . $name, 'type' => 'post', 'published_at' => '2026-11-18 09:00:00', 'slug_value' => $slug]);
    $post->setRelation('images', collect());
    if ($category !== null) {
        $cate = new Category();
        $cate->forceFill(['name' => $category, 'slug_value' => str($category)->slug()->toString()]);
        $post->setRelation('category', $cate);
    } else {
        $post->setRelation('category', null);
    }

    return $post;
}

/** Mock the three repositories the home and layout read. */
function dtMock(object $test, array $mainItems, ?Menu $footer, array $posts): void
{
    $items = \Mockery::mock(MenuItemInterface::class);
    $items->shouldReceive('getMenuItemListByMenuGroup')->with('main_menu', \Mockery::any())->andReturn(collect($mainItems));
    $menus = \Mockery::mock(MenuInterface::class);
    $menus->shouldReceive('getMenuByGroupKey')->andReturn($footer);
    $postRepo = \Mockery::mock(PostInterface::class);
    $postRepo->shouldReceive('getLatestPosts')->andReturn(collect($posts));
    app()->instance(MenuItemInterface::class, $items);
    app()->instance(MenuInterface::class, $menus);
    app()->instance(PostInterface::class, $postRepo);
}

it('has the layout the Content pages extend, with header and footer', function () {
    dtMock($this, [], null, []);

    expect(view()->exists('default::layout.index'))->toBeTrue();
    $html = Blade::render("@extends('default::layout.index')\n@section('content')<p>Module page body</p>@endsection");
    expect($html)->toContain('Module page body')->toContain('<header')->toContain('<footer');
});

it('lists the latest posts and the main menu with its children on the home', function () {
    $footer = new Menu(['group_key' => 'footer_menu', 'name' => 'Footer']);
    $footer->setRelation('items', collect([dtItem('Chính sách', '/chinh-sach')]));
    dtMock($this, [dtItem('Tin tức', '/news'), dtItem('Chuyên mục', '#', [dtItem('Sự kiện', '/su-kien')])], $footer, [
        dtPost('Bài mới nhất', 'bai-moi-nhat', 'Tin tức'),
        dtPost('Bài thứ hai', 'bai-thu-hai', 'Sự kiện'),
    ]);

    $html = $this->get('https://localhost/')->assertOk()->getContent();

    expect($html)->toContain('Bài mới nhất')->toContain('Bài thứ hai')
        ->toContain('href="' . post_route(dtPost('x', 'bai-moi-nhat', 'Tin tức')) . '"')
        ->toContain('Tin tức')->toContain('Chuyên mục')->toContain('Sự kiện')
        ->toContain('Chính sách')
        ->not->toContain(__('default::theme.empty.title'))
        // The theme copy is translated, never a raw `default::theme.*` key (the project provider must load).
        ->toContain(e(__('default::theme.latest.title')))
        ->not->toContain('default::theme.');
});

it('shows the empty state and no menus on a fresh install', function () {
    dtMock($this, [], null, []);

    $html = $this->get('https://localhost/')->assertOk()->getContent();

    expect($html)->toContain(__('default::theme.empty.title'))
        ->toContain('href="' . url('/iPanel/') . '"')
        ->not->toContain('popovertarget="default-menu"');
});

it('serves the news page with the theme layout and links to it from the home', function () {
    dtMock($this, [], null, [dtPost('Có bài', 'co-bai', 'Tin tức')]);

    expect($this->get('https://localhost/')->assertOk()->getContent())->toContain('href="' . route('news') . '"');

    app()->forgetInstance(PostInterface::class); // the news page queries the real repository
    expect($this->get('https://localhost/news')->assertOk()->getContent())
        ->toContain('data-blog-default="news"')->toContain('<header')->toContain('<footer');
});

it('answers an unknown address with a 404, not the home or an error', function () {
    dtMock($this, [], null, []);

    $this->get('https://localhost/khong-co-trang-nay')->assertNotFound();
    $this->get('https://localhost/mot/hai/ba')->assertNotFound();
});

it('renders a post without an image or a category', function () {
    dtMock($this, [], null, [dtPost('Không ảnh', 'khong-anh')]);

    expect($this->get('https://localhost/')->assertOk()->getContent())->toContain('Không ảnh');
});
