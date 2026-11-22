<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Unified widget catalog part 1, Task 5: cloning a hub widget into a site-owned copy moves every
 * stored page use to the clone while filled-in values stay byte-identical.
 */

use App\Events\Widget\WidgetCloned;
use Cms\Content\Models\Post;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Services\Widget\Catalog\WidgetCloneService;
use Cms\Core\Services\Widget\PageWidgetBlockWalker;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

uses(TestCase::class, RefreshDatabase::class);

const CLONE_TEST_USER_ID = 42;

beforeEach(function () {
    // Cloning publishes generated templates: keep them out of the tracked storage tree.
    $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-clone-' . uniqid('', true);
    File::ensureDirectoryExists($this->storageRoot . '/app');
    $this->app->useStoragePath($this->storageRoot);

    // audit_logs.user_id is a foreign key to users.
    DB::table('users')->insert(array_merge(\App\Models\User::factory()->make()->getAttributes(), ['id' => CLONE_TEST_USER_ID, 'password' => 'x']));
    DB::table('languages')->insert([
        ['id' => 1, 'name' => 'Vietnamese', 'path' => 'vi', 'path_alias' => 'vi', 'currency' => 'VND', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'English', 'path' => 'en', 'path_alias' => 'en', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->hub = Widget::query()->forceCreate([
        'widget_key' => 'ispa.hero_banner',
        'name' => 'Hero banner',
        'description' => 'Hub hero',
        'js' => 'console.log(1);',
        'origin' => Widget::ORIGIN_HUB,
        'publisher' => 'ispa',
    ]);
    $this->hubThemeA = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->hub->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<div>A</div>',
    ]);
    $this->hubThemeB = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->hub->id, 'theme_key' => 'dark', 'name' => 'Dark', 'content' => '<div>B</div>',
    ]);
    $this->hubInput = WidgetInput::query()->forceCreate([
        'widget_id' => $this->hub->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text',
    ]);

    $this->other = Widget::query()->forceCreate(['widget_key' => 'ispa.other', 'name' => 'Other', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa']);
    $this->otherTheme = WidgetTheme::query()->forceCreate([
        'widget_id' => $this->other->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>o</p>',
    ]);

    // Filled-in value with unicode, slashes, nesting and numbers: must survive the rewrite unchanged.
    $this->value = ['title' => 'Chào mừng – "Onni"', 'link' => 'https://x.test/a/b', 'items' => [['n' => 1.5, 'on' => true]], 'html' => '<b>x</b>'];

    $this->makePost = function (array $content, array $extra = []): Post {
        $post = new Post();
        $post->forceFill(array_merge([
            'name' => 'Page', 'type' => 'page', 'status' => null, 'language_id' => 1, 'content' => $content,
        ], $extra))->save();

        return $post;
    };

    $this->hubBlock = fn (WidgetTheme $theme, array $extra = []): array => array_merge([
        'id' => 'content_1',
        'type' => 'widget',
        'widget_id' => $this->hub->id,
        'template_id' => $theme->id,
        'widget_key' => $this->hub->widget_key,
        'theme_key' => $theme->theme_key,
        'value' => $this->value,
    ], $extra);

    $this->otherBlock = [
        'type' => 'widget', 'widget_id' => $this->other->id, 'template_id' => $this->otherTheme->id,
        'widget_key' => 'ispa.other', 'theme_key' => 'default', 'value' => ['title' => 'keep'],
    ];

    $this->content = fn (Post $post): array => json_decode((string) DB::table('posts')->where('id', $post->id)->value('content'), true);
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

it('copies widget, themes and inputs with site keys and clone metadata', function () {
    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $hub = $this->hub->fresh();

    expect($r->clone->widget_key)->toBe("site.{$hub->widget_key}-1")
        ->and($r->clone->origin)->toBe('site')
        ->and($r->clone->publisher)->toBeNull()
        ->and($r->clone->cloned_from_widget_key)->toBe($hub->widget_key)
        ->and($r->clone->cloned_at)->not->toBeNull()
        ->and($r->clone->name)->toBe('Hero banner (bản riêng)')
        ->and($r->clone->js)->toBe('console.log(1);')
        ->and($r->clone->template()->orderBy('id')->pluck('theme_key')->all())
        ->toBe($hub->template()->orderBy('id')->pluck('theme_key')->all())
        ->and($r->clone->template()->orderBy('id')->pluck('content')->all())->toBe(['<div>A</div>', '<div>B</div>'])
        ->and($r->clone->inputs()->pluck('input_key')->all())->toBe(['title'])
        ->and($r->themeIdMap)->toHaveKeys([$this->hubThemeA->id, $this->hubThemeB->id])
        ->and(WidgetTheme::query()->find($r->themeIdMap[$this->hubThemeB->id])->widget_id)->toBe($r->clone->id);

    // The hub row and its children are untouched.
    expect($hub->origin)->toBe('hub')->and($hub->publisher)->toBe('ispa')
        ->and($hub->template()->count())->toBe(2)
        ->and($hub->inputs()->count())->toBe(1);
});

it('second clone of the same hub widget gets suffix -2', function () {
    $service = app(WidgetCloneService::class);
    $first = $service->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $second = $service->cloneForSite($this->hub->fresh(), CLONE_TEST_USER_ID);

    expect($first->clone->widget_key)->toBe('site.ispa.hero_banner-1')
        ->and($second->clone->widget_key)->toBe('site.ispa.hero_banner-2');
});

it('rewrites page block references and keeps values byte-identical', function () {
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeB), $this->otherBlock]);
    $untouched = ($this->makePost)([$this->otherBlock]);
    $untouchedRaw = DB::table('posts')->where('id', $untouched->id)->value('content');
    $before = ($this->content)($post);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $after = ($this->content)($post);

    expect($after[0]['widget_id'])->toBe($r->clone->id)
        ->and($after[0]['template_id'])->toBe($r->themeIdMap[$this->hubThemeB->id])
        ->and($after[0]['widget_key'])->toBe($r->clone->widget_key)
        ->and($after[0]['theme_key'])->toBe('dark')
        ->and(json_encode($after[0]['value']))->toBe(json_encode($before[0]['value']))
        ->and($after[0]['value'])->toBe($this->value)
        ->and($after[1])->toBe($before[1])
        ->and(DB::table('posts')->where('id', $untouched->id)->value('content'))->toBe($untouchedRaw)
        ->and($r->pagesUpdated)->toBe(1);
});

it('matches legacy blocks by widget_id and maps template_id when keys are missing', function () {
    $post = ($this->makePost)([[
        'type' => 'widget', 'widget_id' => (string) $this->hub->id, 'template_id' => (string) $this->hubThemeA->id, 'value' => $this->value,
    ]]);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $block = ($this->content)($post)[0];

    expect($block['widget_id'])->toBe($r->clone->id)
        ->and($block['template_id'])->toBe($r->themeIdMap[$this->hubThemeA->id])
        ->and($block['widget_key'])->toBe($r->clone->widget_key)
        ->and($block['theme_key'])->toBe('default')
        ->and($block['value'])->toBe($this->value);
});

it('remaps the stored inputs snapshot to the clone input rows', function () {
    $snapshot = [array_merge($this->hubInput->fresh()->toArray(), ['extra' => 'kept'])];
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeA, ['inputs' => $snapshot])]);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $input = ($this->content)($post)[0]['inputs'][0];
    $cloneInput = $r->clone->inputs()->first();

    expect($input['id'])->toBe($cloneInput->id)
        ->and($input['widget_id'])->toBe($r->clone->id)
        ->and($input['input_key'])->toBe('title')
        ->and($input['extra'])->toBe('kept');
});

it('rewrites nested and translated widget blocks on clone', function () {
    $source = ($this->makePost)([
        ['type' => 'columns', 'columns' => [
            ['children' => [($this->hubBlock)($this->hubThemeA)]],
            ['children' => [$this->otherBlock]],
        ]],
    ]);
    $translation = ($this->makePost)(
        [['type' => 'text', 'value' => 'Hello'], ($this->hubBlock)($this->hubThemeB)],
        ['language_id' => 2, 'parent_id' => $source->id, 'name' => 'Page (en)'],
    );

    // RealEstate stores single widget blocks in contents.custom (no widget_key).
    $contentId = DB::table('contents')->insertGetId([
        'uuid' => (string) str()->uuid(), 'name' => 'Property block', 'language_id' => 1,
        'custom' => json_encode(['type' => 'widget', 'widget_id' => $this->hub->id, 'template_id' => $this->hubThemeB->id, 'inputs' => [], 'value' => $this->value]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);

    $nested = ($this->content)($source)[0]['columns'];
    $translated = ($this->content)($translation);
    $custom = json_decode((string) DB::table('contents')->where('id', $contentId)->value('custom'), true);

    expect($nested[0]['children'][0]['widget_id'])->toBe($r->clone->id)
        ->and($nested[0]['children'][0]['template_id'])->toBe($r->themeIdMap[$this->hubThemeA->id])
        ->and($nested[0]['children'][0]['value'])->toBe($this->value)
        ->and($nested[1]['children'][0])->toBe($this->otherBlock)
        ->and($translated[0])->toBe(['type' => 'text', 'value' => 'Hello'])
        ->and($translated[1]['widget_key'])->toBe($r->clone->widget_key)
        ->and($translated[1]['template_id'])->toBe($r->themeIdMap[$this->hubThemeB->id])
        ->and($translated[1]['value'])->toBe($this->value)
        ->and($custom['widget_id'])->toBe($r->clone->id)
        ->and($custom['template_id'])->toBe($r->themeIdMap[$this->hubThemeB->id])
        ->and($custom['value'])->toBe($this->value)
        ->and($r->pagesUpdated)->toBe(2);
});

it('keeps a page edit saved between the scan and the write', function () {
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);
    $edited = [['type' => 'text', 'value' => 'Saved meanwhile'], ($this->hubBlock)($this->hubThemeA)];

    // Simulates another editor committing a save after the unlocked scan picked this row.
    $service = new class(app(PageWidgetBlockWalker::class), $post->id, $edited) extends WidgetCloneService {
        public function __construct(PageWidgetBlockWalker $walker, private int $postId, private array $edited)
        {
            parent::__construct($walker, app(WidgetAuditRecorder::class));
        }

        protected function readRowForUpdate(string $table, string $column, int|string $id): ?string
        {
            if ($table === 'posts' && (int) $id === $this->postId) {
                DB::table('posts')->where('id', $id)->update(['content' => json_encode($this->edited)]);
            }

            return parent::readRowForUpdate($table, $column, $id);
        }
    };

    $r = $service->cloneForSite($this->hub, CLONE_TEST_USER_ID);
    $after = ($this->content)($post);

    expect($after)->toHaveCount(2)
        ->and($after[0])->toBe(['type' => 'text', 'value' => 'Saved meanwhile'])
        ->and($after[1]['widget_id'])->toBe($r->clone->id)
        ->and($after[1]['value'])->toBe($this->value)
        ->and($r->pagesUpdated)->toBe(1);
});

it('dispatches WidgetCloned after commit', function () {
    $levelBefore = DB::transactionLevel();
    $seen = [];
    Event::listen(WidgetCloned::class, function (WidgetCloned $event) use (&$seen) {
        $seen[] = ['level' => DB::transactionLevel(), 'event' => $event];
    });

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['level'])->toBe($levelBefore)
        ->and($seen[0]['event']->hubWidgetKey)->toBe('ispa.hero_banner')
        ->and($seen[0]['event']->cloneWidgetId)->toBe($r->clone->id)
        ->and($seen[0]['event']->themeIdMap)->toBe($r->themeIdMap)
        ->and($seen[0]['event']->userId)->toBe(CLONE_TEST_USER_ID);
});

it('rolls back everything when the page rewrite fails', function () {
    Event::fake([WidgetCloned::class]);
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);
    $raw = DB::table('posts')->where('id', $post->id)->value('content');
    $widgetCount = Widget::query()->count();
    $themeCount = WidgetTheme::query()->count();
    $inputCount = WidgetInput::query()->count();

    app()->instance(PageWidgetBlockWalker::class, new class extends PageWidgetBlockWalker {
        public function walk(array $content, callable $visit, array $path = []): array
        {
            throw new RuntimeException('rewrite failed');
        }
    });

    expect(fn () => app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID))
        ->toThrow(RuntimeException::class, 'rewrite failed');

    expect(Widget::query()->count())->toBe($widgetCount)
        ->and(WidgetTheme::query()->count())->toBe($themeCount)
        ->and(WidgetInput::query()->count())->toBe($inputCount)
        ->and(DB::table('posts')->where('id', $post->id)->value('content'))->toBe($raw);
    Event::assertNotDispatched(WidgetCloned::class);
});

it('refuses to clone a site widget', function () {
    $site = Widget::query()->forceCreate(['widget_key' => 'site.thing', 'name' => 'Site', 'origin' => Widget::ORIGIN_SITE]);

    expect(fn () => app(WidgetCloneService::class)->cloneForSite($site, CLONE_TEST_USER_ID))
        ->toThrow(LogicException::class);
});

it('writes a widget.cloned audit row with the clone options', function () {
    ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);

    $row = AuditLog::query()->where('action', 'widget.cloned')->sole();
    expect($row->target_type)->toBe('widget')
        ->and((int) $row->target_id)->toBe($r->clone->id)
        ->and((int) $row->user_id)->toBe(CLONE_TEST_USER_ID)
        ->and($row->options)->toBe([
            'hub_key' => 'ispa.hero_banner',
            'clone_key' => $r->clone->widget_key,
            'pages_updated' => 1,
        ]);
});

it('leaves no audit row when the clone rolls back', function () {
    app()->instance(PageWidgetBlockWalker::class, new class extends PageWidgetBlockWalker {
        public function walk(array $content, callable $visit, array $path = []): array
        {
            throw new RuntimeException('rewrite failed');
        }
    });
    ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);

    expect(fn () => app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID))
        ->toThrow(RuntimeException::class);

    expect(AuditLog::query()->where('action', 'widget.cloned')->count())->toBe(0);
});

it('reuses a clone of the same hub only within the reuse window', function () {
    $service = app(WidgetCloneService::class);
    $first = $service->cloneOrReuseForSite($this->hub, CLONE_TEST_USER_ID);
    $reused = $service->cloneOrReuseForSite($this->hub->fresh(), CLONE_TEST_USER_ID);

    expect($reused->clone->id)->toBe($first->clone->id)
        ->and($reused->pagesUpdated)->toBe(0)
        ->and($reused->themeIdMap)->toBe($first->themeIdMap);

    $this->travel(WidgetCloneService::REUSE_CLONE_WITHIN_SECONDS + 1)->seconds();
    $later = $service->cloneOrReuseForSite($this->hub->fresh(), CLONE_TEST_USER_ID);

    expect($later->clone->id)->not->toBe($first->clone->id)
        ->and($later->clone->widget_key)->toBe('site.ispa.hero_banner-2');
});

it('clone name carries the site-copy suffix once', function () {
    expect(WidgetCloneService::cloneName('Hero banner'))->toBe('Hero banner (bản riêng)')
        ->and(WidgetCloneService::cloneName('Hero banner (bản riêng)'))->toBe('Hero banner (bản riêng)');
});

it('publishes the clone template files before the pages that use them commit', function () {
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);

    $r = app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);

    $block = ($this->content)($post)[0];
    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($block['widget_id'])->toBe($r->clone->id)
        ->and($manifest)->not->toBeNull()
        ->and(LegacyWidgetArtifactSetPublisher::resolveProjectFilePath(
            $manifest, 'Default', "widget_{$r->clone->id}_theme_{$block['template_id']}.blade.php"
        ))->not->toBeNull();
});

it('rolls back the clone and the page rewrite when publishing the clone templates fails', function () {
    Event::fake([WidgetCloned::class]);
    $post = ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);
    $raw = DB::table('posts')->where('id', $post->id)->value('content');
    $widgetCount = Widget::query()->count();
    $themeCount = WidgetTheme::query()->count();

    // A readiness barrier that refuses makes publish() throw before any pointer switch.
    app()->instance(LegacyWidgetArtifactSetPublisher::class, new LegacyWidgetArtifactSetPublisher(fn (): bool => false));

    expect(fn () => app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID))
        ->toThrow(RuntimeException::class);

    expect(Widget::query()->count())->toBe($widgetCount)
        ->and(WidgetTheme::query()->count())->toBe($themeCount)
        ->and(DB::table('posts')->where('id', $post->id)->value('content'))->toBe($raw)
        ->and(LegacyWidgetArtifactSetPublisher::readActiveManifest())->toBeNull();
    Event::assertNotDispatched(WidgetCloned::class);
});

it('forgets the slug detail cache of rewritten pages after commit', function () {
    if (!Schema::hasTable('slugs')) {
        Schema::create('slugs', function ($table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->nullableMorphs('target');
            $table->unsignedBigInteger('language_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $page = ($this->makePost)([($this->hubBlock)($this->hubThemeA)]);
    $untouched = ($this->makePost)([$this->otherBlock]);
    DB::table('slugs')->insert([
        ['slug' => 'trang-chu', 'target_type' => $page->getMorphClass(), 'target_id' => $page->id, 'language_id' => 1],
        ['slug' => 'khac', 'target_type' => $untouched->getMorphClass(), 'target_id' => $untouched->id, 'language_id' => 1],
    ]);
    $pageKey = CacheKeyHelper::slugDetail('trang-chu', 1);
    $otherKey = CacheKeyHelper::slugDetail('khac', 1);
    CMSCache::put($pageKey, 'stale', 3600);
    CMSCache::put($otherKey, 'kept', 3600);

    app(WidgetCloneService::class)->cloneForSite($this->hub, CLONE_TEST_USER_ID);

    expect(CMSCache::get($pageKey))->toBeNull()
        ->and(CMSCache::get($otherKey))->toBe('kept');
});
