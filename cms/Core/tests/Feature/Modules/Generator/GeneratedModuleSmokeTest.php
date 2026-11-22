<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Tests\Feature\Modules\Generator\Support\GeneratedModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

// Each mode of cms:make-module produces a module that loads, migrates and serves its sample entity.
// Loaded classes stay in the PHP process, so each mode loads under its own names: lite Blog/Post, normal News/Article,
// full Zoo/Animal (generation-only checks with load: false may reuse any name).

afterEach(function (): void {
    ($this->generated ?? null)?->dispose();
});

function actingAsLocalAdmin(): void
{
    // AbstractModelPolicy lets every check pass in the local environment.
    app()['env'] = 'local';
    Sanctum::actingAs(User::factory()->create());
}

function assertCrudRoundTrip($test, string $base): void
{
    actingAsLocalAdmin();
    $uuid = $test->postJson($base, ['name' => 'Hello'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hello')
        ->json('data.uuid');
    expect($uuid)->toBeString();

    $test->getJson($base . '?search=Hel&pageSize=5')
        ->assertOk()
        ->assertJsonPath('data.data.0.uuid', $uuid)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.perPage', 5);
    $test->getJson($base . '?search=nothing-like-it')->assertOk()->assertJsonPath('data.total', 0);

    $test->getJson("{$base}/{$uuid}")->assertOk()->assertJsonPath('data.uuid', $uuid);
    $test->putJson("{$base}/{$uuid}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $test->deleteJson("{$base}/{$uuid}")->assertOk();
    $test->getJson("{$base}/{$uuid}")->assertNotFound();
    $test->getJson("{$base}/1")->assertNotFound();
}

function assertCleanAndLinted(GeneratedModule $generated): void
{
    $leftovers = [];
    foreach ($generated->files() as $relative) {
        $content = file_get_contents($generated->path($relative));
        if (preg_match('/skeleton|thing|__CORE_REQUIREMENT__|@mode|@endmode/i', $relative . "\n" . $content) === 1) {
            $leftovers[] = $relative;
        }
        if (str_ends_with($relative, '.js')) {
            // base-admin imports every cms/*/admin/module.js eagerly: one syntax error breaks the whole admin build.
            $out = [];
            // Parsed as an ES module from stdin: `node --check file.js` accepts invalid ESM in a typeless folder.
            exec('node --input-type=module --check < ' . escapeshellarg($generated->path($relative)) . ' 2>&1', $out, $code);
            expect($code)->toBe(0, $relative . ': ' . implode("\n", $out));
        }
        if (str_ends_with($relative, '.php')) {
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($generated->path($relative)) . ' 2>&1', $out, $code);
            expect($code)->toBe(0, $relative . ': ' . implode("\n", $out));
        }
    }
    expect($leftovers)->toBe([]);

    // manifest-lint resolves `requires`, so it needs Core beside the new module.
    $lintDir = sys_get_temp_dir() . '/genlint-' . bin2hex(random_bytes(4));
    mkdir($lintDir);
    symlink(module_path('Core'), $lintDir . '/Core');
    symlink($generated->root, $lintDir . '/' . $generated->module);
    try {
        expect(Artisan::call('cms:manifest-lint', ['--path' => $lintDir]))->toBe(0, Artisan::output());
    } finally {
        unlink($lintDir . '/Core');
        unlink($lintDir . '/' . $generated->module);
        rmdir($lintDir);
    }
}

beforeEach(function (): void {
    $this->withoutMiddleware();
});

it('serves the lite sample entity through the admin API', function (): void {
    $this->generated = GeneratedModule::make('lite');

    assertCrudRoundTrip($this, '/api/v2/admin/blog/posts');
});

it('forbids a user without the permission in lite mode', function (): void {
    $this->generated = GeneratedModule::make('lite');
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/admin/blog/posts')->assertForbidden();
    $this->postJson('/api/v2/admin/blog/posts', ['name' => 'x'])->assertForbidden();
    // Without the permission the API does not tell whether a uuid exists.
    $unknown = '00000000-0000-4000-8000-000000000000';
    $this->getJson('/api/v2/admin/blog/posts/' . $unknown)->assertForbidden();
    $this->putJson('/api/v2/admin/blog/posts/' . $unknown, ['name' => 'x'])->assertForbidden();
    $this->deleteJson('/api/v2/admin/blog/posts/' . $unknown)->assertForbidden();
});

it('leaves no placeholder and passes lint in lite mode', function (): void {
    $this->generated = GeneratedModule::make('lite', load: false);

    assertCleanAndLinted($this->generated);
    expect($this->generated->files())->toContain('app/Models/BlogPost.php', 'tests/Feature/BlogPostAdminApiTest.php');
});

it('handles multi-word names', function (): void {
    $this->generated = GeneratedModule::make('lite', 'KnowledgeBase', 'PostCategory');

    assertCleanAndLinted($this->generated);
    expect(Schema::hasTable('knowledge_base_post_categories'))->toBeTrue();
    assertCrudRoundTrip($this, '/api/v2/admin/knowledge-base/post-categories');
});

it('runs the normal sample entity through its service', function (): void {
    $this->generated = GeneratedModule::make('normal', 'News', 'Article');

    assertCrudRoundTrip($this, '/api/v2/admin/news/articles');
    $controller = file_get_contents($this->generated->path('app/Http/Controllers/V2/Admin/NewsArticleController.php'));
    expect($controller)->toContain('NewsArticleService')->not->toContain('NewsArticle::query()->create(');
});

it('imports the normal module translations with both languages', function (): void {
    $this->generated = GeneratedModule::make('normal', 'News', 'Article');

    $this->seed('Cms\\News\\Database\\Seeders\\NewsTranslateTableSeeder');

    foreach (['nav.a.news', 'nav.a.news-articles', 'text.news.articles.empty', 'text.news.articles.status_published'] as $key) {
        $row = \Illuminate\Support\Facades\DB::table('translates')->where('name', $key)->first();
        expect($row)->not->toBeNull($key);
        $text = json_decode((string) $row->text, true);
        expect($text['en'] ?? '')->not->toBe('')->and($text['vi'] ?? '')->not->toBe('');
    }
});

it('writes the normal admin module wiring', function (): void {
    $this->generated = GeneratedModule::make('normal', load: false);

    assertCleanAndLinted($this->generated);
    expect(file_get_contents($this->generated->path('admin/module.js')))->toContain('export const BlogAdminModule')->toContain("key: 'blog'")
            // base-admin builds routes and the menu only for named modules and modules with a `slot`.
            ->toContain('slot: {')
        ->and(file_get_contents($this->generated->path('admin/navigation-config.js')))->toContain("rule: 'blog.blog_post.viewAny'")->toContain("module: 'Blog'")
        ->and(file_get_contents($this->generated->path('admin/api.js')))->toContain("'blog/posts'")
        ->and($this->generated->files())->toContain('admin/pages/posts.vue', 'database/seeders/sql/translate-admin-authored.php');
});

it('handles multi-word names in normal mode', function (): void {
    $this->generated = GeneratedModule::make('normal', 'KnowledgeBase', 'PostCategory', load: false);

    assertCleanAndLinted($this->generated);
    expect(file_get_contents($this->generated->path('admin/api.js')))->toContain("'knowledge-base/post-categories'")
        ->toContain('getKnowledgeBasePostCategories');
});

it('serves the full public pages of published items only', function (): void {
    // Not "Blog": the Content module already answers /blog (the command warns about such a clash).
    $this->generated = GeneratedModule::make('full', 'Zoo', 'Animal');
    $this->generated->enable();
    config(['zoo.public.layout' => 'errors.layout']);
    // Real web middleware (the module's own EnsureZooEnabled included), except the redirect to https.
    $this->withMiddleware()->withoutMiddleware(\App\Http\Middleware\HttpsProtocol::class);
    $published = \Cms\Zoo\Models\ZooAnimal::query()->create(['name' => 'Visible animal', 'status' => 'published']);
    $draft = \Cms\Zoo\Models\ZooAnimal::query()->create(['name' => 'Hidden draft', 'status' => 'draft']);

    $this->get('/zoo')->assertOk()->assertSee('Visible animal')->assertDontSee('Hidden draft');
    $this->get('/zoo/' . $published->uuid)->assertOk()->assertSee('Visible animal');
    $this->get('/zoo/' . $draft->uuid)->assertNotFound();

    $this->generated->enable(false);
    $this->get('/zoo')->assertNotFound();
});

it('queues and sends the full saved notification only when an address is set', function (): void {
    $this->generated = GeneratedModule::make('full', 'Zoo', 'Animal');
    \Illuminate\Support\Facades\Bus::fake();
    $service = app(\Cms\Zoo\Services\ZooAnimalService::class);

    $service->create(['name' => 'Quiet']);
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\Cms\Zoo\Jobs\SendZooAnimalNotification::class);

    config(['zoo.notify_email' => 'editor@example.test']);
    $animal = $service->create(['name' => 'Loud']);
    \Illuminate\Support\Facades\Bus::assertDispatched(\Cms\Zoo\Jobs\SendZooAnimalNotification::class);

    \Illuminate\Support\Facades\Mail::fake();
    (new \Cms\Zoo\Jobs\SendZooAnimalNotification($animal->id))->handle();
    \Illuminate\Support\Facades\Mail::assertSent(\Cms\Zoo\Mail\ZooAnimalSavedMail::class, static fn ($mail): bool => $mail->hasTo('editor@example.test'));
});

it('purges old trashed items with the full command', function (): void {
    $this->generated = GeneratedModule::make('full', 'Zoo', 'Animal');
    $old = \Cms\Zoo\Models\ZooAnimal::query()->create(['name' => 'Old']);
    $recent = \Cms\Zoo\Models\ZooAnimal::query()->create(['name' => 'Recent']);
    $old->delete();
    $recent->delete();
    \Cms\Zoo\Models\ZooAnimal::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(40)]);
    \Cms\Zoo\Models\ZooAnimal::withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(5)]);

    // A provider registered after boot misses Artisan's start-up hook, so the test registers the command itself.
    \Illuminate\Support\Facades\Artisan::registerCommand(app(\Cms\Zoo\Console\Commands\PurgeTrashedZooAnimalsCommand::class));
    $this->artisan('zoo:purge-trashed')->assertSuccessful();
    expect(\Cms\Zoo\Models\ZooAnimal::withTrashed()->pluck('name')->all())->toBe(['Recent']);

    $this->artisan('zoo:purge-trashed', ['--days' => '0'])->assertFailed();
});

it('runs the full admin API with the status enum and data object', function (): void {
    $this->generated = GeneratedModule::make('full', 'Zoo', 'Animal');

    assertCrudRoundTrip($this, '/api/v2/admin/zoo/animals');
    actingAsLocalAdmin();
    $this->postJson('/api/v2/admin/zoo/animals', ['name' => '  Trimmed  ', 'status' => 'published'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Trimmed')
        ->assertJsonPath('data.status', 'published');
});

it('writes clean full modules for single and multi-word names', function (string $module, string $entity): void {
    $this->generated = GeneratedModule::make('full', $module, $entity, load: false);

    assertCleanAndLinted($this->generated);
})->with([['Zoo', 'Animal'], ['KnowledgeBase', 'PostCategory']]);

it('lists full public pages for an entity whose plural is the singular', function (): void {
    // Str::plural('News') === 'News': the list and the loop item must not share a variable.
    $this->generated = GeneratedModule::make('full', 'Press', 'News');
    $this->generated->enable();
    config(['press.public.layout' => 'errors.layout']);
    $this->withMiddleware()->withoutMiddleware(\App\Http\Middleware\HttpsProtocol::class);
    \Cms\Press\Models\PressNews::query()->create(['name' => 'Launch day', 'status' => 'published']);

    $this->get('/press')->assertOk()->assertSee('Launch day');
});

it('searches with % and _ as plain characters', function (string $mode, string $module, string $entity, string $base): void {
    $this->generated = GeneratedModule::make($mode, $module, $entity);
    actingAsLocalAdmin();
    foreach (['100% pure', '100 pure', 'a_b', 'axb'] as $name) {
        $this->postJson($base, ['name' => $name])->assertOk();
    }

    $this->getJson($base . '?search=' . rawurlencode('100%'))->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson($base . '?search=' . rawurlencode('a_b'))->assertOk()->assertJsonPath('data.total', 1);
})->with([
    'lite' => ['lite', 'Blog', 'Post', '/api/v2/admin/blog/posts'],
    'normal' => ['normal', 'News', 'Article', '/api/v2/admin/news/articles'],
]);

it('lets a role holding the seeded permissions use the admin API outside the local bypass', function (): void {
    // The seeder's groups and the policy prefix must name the same keys; the local environment would hide a mismatch.
    $this->generated = GeneratedModule::make('lite', 'OrgChart', 'TeamUnit');
    $this->seed('Cms\\OrgChart\\Database\\Seeders\\OrgChartPermissionSeeder');
    $role = \Cms\Core\Models\Role::query()->create(['name' => 'org-chart-editor', 'title' => 'Org chart editor']);
    $role->permissions()->sync(\Cms\Core\Models\Permission::query()->where('group', 'org-chart.org_chart_team_unit')->pluck('id'));
    $user = User::factory()->create();
    $user->roles()->sync([$role->id]);
    expect(app()->environment('local'))->toBeFalse();
    Sanctum::actingAs($user->fresh());

    $base = '/api/v2/admin/org-chart/team-units';
    $this->getJson($base)->assertOk();
    $uuid = $this->postJson($base, ['name' => 'Board'])->assertOk()->json('data.uuid');
    $this->putJson("{$base}/{$uuid}", ['name' => 'Council'])->assertOk()->assertJsonPath('data.name', 'Council');
    $this->deleteJson("{$base}/{$uuid}")->assertOk();
});
