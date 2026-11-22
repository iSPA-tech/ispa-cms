<?php

/**
 * Widget hub distribution, Task 7: at switch-over every hub widget already on a site is either stamped with the hub
 * version whose content it equals, or turned into a site copy (C2). Ids and page JSON never change, nothing is deleted.
 * The hub is faked by binding a PlatformHubClient pointed at https://hub.test and answering signed like the real hub.
 */

use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_ADOPT_SECRET = 'hub-adopt-test-secret';
const WIDGET_ADOPT_PATH = '/api/v2/public/platform/widgets';

/** A hub widget with one design and one input, plus its generated template file. */
function adoptHubWidget(object $test, string $key, string $template = '<p>{title}</p>'): Widget
{
    $widget = Widget::query()->forceCreate([
        'widget_key' => $key, 'name' => 'Hero', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'iSPA',
    ]);
    $theme = WidgetTheme::query()->forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => $template,
        'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]);
    WidgetInput::query()->forceCreate([
        'widget_id' => $widget->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text',
        'default_value' => null, 'options' => null,
    ]);
    File::put($test->root . "/storage/app/generated_widgets/default/widget_{$widget->id}_theme_{$theme->id}.blade.php", $template);

    return $widget->fresh();
}

function adoptPost(array $content): int
{
    DB::table('enumerates')->insertOrIgnore(['enum_key' => 'status_published', 'name' => 'status_published']);

    return (int) DB::table('posts')->insertGetId([
        'name' => 'Page', 'type' => 'page', 'status' => 'status_published',
        'content' => json_encode($content), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function adoptBlock(Widget $widget, string $title, bool $withKeys = false): array
{
    $theme = $widget->template()->first();
    $block = ['type' => 'widget', 'widget_id' => $widget->id, 'template_id' => $theme->id, 'value' => ['title' => $title], 'inputs' => []];

    return $withKeys ? $block + ['widget_key' => $widget->widget_key, 'theme_key' => $theme->theme_key] : $block;
}

/** sha256 the hub would publish for the site's current rows. */
function adoptContentHash(Widget $widget): string
{
    return app(WidgetBundleBuilder::class)->build($widget->widget_key, 1, 'iSPA')->contentSha256;
}

/**
 * Serve `$version` of the widget as the hub publishes it from local: the site's rows plus `$extraDesigns` (rows of
 * other projects that non-local sites never seed). Lists the published hash and the signed bundle; returns the hash.
 *
 * @param list<array<string, mixed>> $extraDesigns
 */
function adoptServeBundle(object $test, Widget $widget, int $version, array $extraDesigns, ?string $secretKey = null): string
{
    $site = app(WidgetBundleBuilder::class)->build($widget->widget_key, $version, 'iSPA');
    $bundle = WidgetBundle::create(
        widgetKey: $site->widgetKey, version: $version, publisher: 'iSPA', widget: $site->widget,
        designs: [...$site->designs, ...$extraDesigns], inputs: $site->inputs, assets: [], requires: $site->requires,
    );
    $test->hub[WIDGET_ADOPT_PATH . '/' . $widget->widget_key . '/versions/' . $version] = [200, [
        'sig_json' => (new PackageSigner())->envelope($bundle->toCanonicalJson(), $secretKey ?? $test->secretKey),
    ]];
    ($test->serveVersions)($widget->widget_key, [$version => $bundle->contentSha256]);

    return $bundle->contentSha256;
}

function adoptOtherProjectDesign(string $themeKey = 'bhaku'): array
{
    return [
        'theme_key' => $themeKey, 'name' => 'BHaku', 'content' => '<div>{title}</div>', 'css' => null, 'query' => null,
        'project_name' => ['BHaku'], 'preview_png_base64' => null,
    ];
}

function adoptSnapshot(object $test, string $name): array
{
    expect(Artisan::call('cms:widget-render-snapshot', ['--out' => $test->root . '/' . $name]))->toBe(0);

    return json_decode(File::get($test->root . '/' . $name . '/index.json'), true);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ispa-widget-hub-adopt-' . uniqid('', true);
    File::ensureDirectoryExists($this->root . '/storage/app/generated_widgets/default');
    $this->app->useStoragePath($this->root . '/storage');
    config()->set('ispa-cms.folder_name', 'Default');

    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $this->secretKey = sodium_crypto_sign_secretkey($pair);
    config([
        'core.module_packages.trusted_keys' => [KeyId::fromPublicKey($public) => base64_encode($public)],
        'core.module_packages.widget_bundle_key_ids' => [KeyId::fromPublicKey($public)],
        'core.module_packages.revoked_key_ids' => [],
    ]);

    // The fake hub: path => [status, json body]; unknown paths answer 404 like the real hub.
    $this->hub = [];
    $this->hubDown = false;
    $this->app->instance(PlatformHubClient::class, new PlatformHubClient('site.test', WIDGET_ADOPT_SECRET, 'https://hub.test'));
    Http::fake(function (ClientRequest $request) {
        if ($this->hubDown) {
            return Http::response('down', 502);
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        [$status, $payload] = $this->hub[$path] ?? [404, ['error_code' => 'WIDGET_HUB_NOT_FOUND']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = (string) ($request->header(PlatformSignature::HEADER_NONCE)[0] ?? '');

        return Http::response($body, $status, [
            'Content-Type' => 'application/json',
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response(WIDGET_ADOPT_SECRET, $status, $nonce, $body),
        ]);
    });

    // Lists the versions; a version with no bundle served yet gets a signed bundle whose content differs from the site.
    $this->serveVersions = function (string $key, array $hashByVersion): void {
        $this->hub[WIDGET_ADOPT_PATH . '/' . $key . '/versions'] = [200, ['versions' => array_map(
            static fn (int $v, string $hash): array => ['version' => $v, 'content_sha256' => $hash],
            array_keys($hashByVersion),
            array_values($hashByVersion),
        )]];
        foreach (array_keys($hashByVersion) as $version) {
            $this->hub[WIDGET_ADOPT_PATH . '/' . $key . '/versions/' . $version] ??= [200, ['sig_json' => (new PackageSigner())->envelope(WidgetBundle::create(
                widgetKey: $key, version: $version, publisher: 'iSPA', widget: ['name' => 'Hero', 'description' => null, 'js' => null],
                designs: [['theme_key' => 'default', 'name' => 'Default', 'content' => "<p>hub v{$version}</p>", 'css' => null, 'query' => null, 'project_name' => ['Default'], 'preview_png_base64' => null]],
                inputs: [], assets: [], requires: ['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION, 'resolvers' => []],
            )->toCanonicalJson(), $this->secretKey)]];
        }
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('equal content is stamped with the hub version', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    ($this->serveVersions)('example.hero', [1 => str_repeat('b', 64), 2 => adoptContentHash($widget), 3 => str_repeat('c', 64)]);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('example.hero');

    $fresh = $widget->fresh();
    expect($fresh->widget_key)->toBe('example.hero')
        ->and($fresh->origin)->toBe(Widget::ORIGIN_HUB)
        ->and((int) $fresh->version)->toBe(2)
        ->and($fresh->cloned_from_widget_key)->toBeNull();
});

it('differing content becomes a site copy and pages render the same', function () {
    $widget = adoptHubWidget($this, 'example.hero', '<section>{title}</section>');
    $themeIds = $widget->template()->pluck('id')->all();
    $inputIds = $widget->inputs()->pluck('id')->all();
    // A local widget already holds the first clone key, so the conversion takes the next one.
    Widget::query()->forceCreate(['widget_key' => 'site.example.hero-1', 'name' => 'Old copy', 'origin' => Widget::ORIGIN_SITE]);
    ($this->serveVersions)('example.hero', [1 => str_repeat('b', 64)]);
    $postId = adoptPost([['type' => 'text', 'value' => '<p>x</p>'], adoptBlock($widget, 'Alpha')]);
    $storedBefore = DB::table('posts')->where('id', $postId)->value('content');
    $before = adoptSnapshot($this, 'before');

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-2');

    $fresh = Widget::query()->find($widget->id);
    expect($fresh->widget_key)->toBe('site.example.hero-2')
        ->and($fresh->origin)->toBe(Widget::ORIGIN_SITE)
        ->and($fresh->publisher)->toBeNull()
        ->and($fresh->cloned_from_widget_key)->toBe('example.hero')
        ->and($fresh->cloned_at)->not->toBeNull()
        ->and($fresh->name)->toBe('Hero (bản riêng)')
        ->and($fresh->template()->pluck('id')->all())->toBe($themeIds)
        ->and($fresh->inputs()->pluck('id')->all())->toBe($inputIds)
        ->and(DB::table('posts')->where('id', $postId)->value('content'))->toBe($storedBefore)
        ->and(adoptSnapshot($this, 'after'))->toBe($before)
        ->and(Widget::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('target_type', 'widget')->where('target_id', $widget->id)->count())->toBe(1);
});

it('a widget the hub does not have is kept unchanged by default', function () {
    $widget = adoptHubWidget($this, 'example.gone');
    $before = (array) DB::table('widgets')->where('id', $widget->id)->first();

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('không có trên hub — giữ nguyên');

    expect((array) DB::table('widgets')->where('id', $widget->id)->first())->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('a widget the hub does not have becomes a site copy only with --convert-missing', function () {
    $widget = adoptHubWidget($this, 'example.gone');

    expect(Artisan::call('cms:widget-hub-adopt', ['--convert-missing' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('not on the hub');

    expect($widget->fresh()->widget_key)->toBe('site.example.gone-1')
        ->and($widget->fresh()->cloned_from_widget_key)->toBe('example.gone');
});

it('refuses the whole run when a version list is empty or malformed', function (array $payload) {
    $first = adoptHubWidget($this, 'example.first');
    $second = adoptHubWidget($this, 'example.second');
    ($this->serveVersions)('example.first', [1 => str_repeat('b', 64)]);
    $this->hub[WIDGET_ADOPT_PATH . '/example.second/versions'] = [200, $payload];
    $rows = DB::table('widgets')->orderBy('id')->get()->toArray();

    expect(Artisan::call('cms:widget-hub-adopt', ['--convert-missing' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('unreachable');

    expect(DB::table('widgets')->orderBy('id')->get()->toArray())->toEqual($rows);
})->with([
    'empty list' => [['versions' => []]],
    'malformed rows' => [['versions' => [['version' => '1', 'content_sha256' => 5]]]],
]);

it('hashes a widget without publisher as iSPA, like the bundle build command', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    ($this->serveVersions)('example.hero', [3 => adoptContentHash($widget)]);
    DB::table('widgets')->where('id', $widget->id)->update(['publisher' => null]);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);

    expect($widget->fresh()->widget_key)->toBe('example.hero')
        ->and((int) $widget->fresh()->version)->toBe(3);
});

it('prints what was done before a failure partway through', function () {
    $first = adoptHubWidget($this, 'example.first');
    $second = adoptHubWidget($this, 'example.second');
    ($this->serveVersions)('example.first', [2 => adoptContentHash($first)]);
    ($this->serveVersions)('example.second', [1 => str_repeat('b', 64)]);
    // The conversion of the second widget fails in the database after the first widget was stamped.
    DB::statement("CREATE TRIGGER adopt_fail BEFORE UPDATE OF widget_key ON widgets BEGIN SELECT RAISE(ABORT, 'boom'); END");

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(1);

    expect(Artisan::output())->toContain('example.first')->toContain('recorded as downloaded')->toContain('boom')
        ->and((int) $first->fresh()->version)->toBe(2)
        ->and($second->fresh()->widget_key)->toBe('example.second');
});

it('dry run writes nothing', function () {
    $same = adoptHubWidget($this, 'example.same');
    $diff = adoptHubWidget($this, 'example.diff');
    ($this->serveVersions)('example.same', [4 => adoptContentHash($same)]);
    ($this->serveVersions)('example.diff', [1 => str_repeat('b', 64)]);
    $rows = DB::table('widgets')->orderBy('id')->get()->toArray();

    expect(Artisan::call('cms:widget-hub-adopt', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('example.same')->toContain('site.example.diff-1');

    expect(DB::table('widgets')->orderBy('id')->get()->toArray())->toEqual($rows)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses to run while the hub is unreachable and writes nothing', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    $this->hubDown = true;

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(1);

    expect($widget->fresh()->widget_key)->toBe('example.hero')
        ->and((int) $widget->fresh()->version)->toBe(1);
});

it('a block referencing a converted widget by key renders the same', function () {
    $widget = adoptHubWidget($this, 'example.hero', '<section>{title}</section>');
    $theme = $widget->template()->first();
    ($this->serveVersions)('example.hero', [1 => str_repeat('b', 64)]);
    $keyed = adoptPost([['type' => 'text', 'value' => '<p>x</p>'], ['type' => 'row', 'children' => [adoptBlock($widget, 'Alpha', withKeys: true)]]]);
    $byId = adoptPost([adoptBlock($widget, 'Beta')]);
    $byIdStored = DB::table('posts')->where('id', $byId)->value('content');
    $contentId = DB::table('contents')->insertGetId([
        'uuid' => (string) str()->uuid(), 'name' => 'Property block', 'custom' => json_encode(adoptBlock($widget, 'Gamma', withKeys: true)),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = adoptSnapshot($this, 'before');

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-1');

    $block = json_decode(DB::table('posts')->where('id', $keyed)->value('content'), true)[1]['children'][0];
    $custom = json_decode(DB::table('contents')->where('id', $contentId)->value('custom'), true);
    expect($block['widget_key'])->toBe('site.example.hero-1')
        ->and($block['theme_key'])->toBe('default')
        ->and($block['widget_id'])->toBe($widget->id)
        ->and($block['template_id'])->toBe($theme->id)
        ->and($block['value'])->toBe(['title' => 'Alpha'])
        ->and($custom['widget_key'])->toBe('site.example.hero-1')
        ->and($custom['widget_id'])->toBe($widget->id)
        ->and(DB::table('posts')->where('id', $byId)->value('content'))->toBe($byIdStored)
        ->and(adoptSnapshot($this, 'after'))->toBe($before);

    $audit = AuditLog::query()->where('target_type', 'widget')->where('target_id', $widget->id)->first();
    expect($audit->action)->toBe('widget.cloned')
        ->and($audit->user_id)->toBeNull()
        ->and($audit->options['actor'])->toBe('console')
        ->and($audit->options['pages_updated'])->toBe(1);
});

it('dry run lists the pages a key rewrite would change and writes nothing', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    ($this->serveVersions)('example.hero', [1 => str_repeat('b', 64)]);
    $keyed = adoptPost([adoptBlock($widget, 'Alpha', withKeys: true)]);
    $stored = DB::table('posts')->where('id', $keyed)->value('content');
    $rows = DB::table('widgets')->orderBy('id')->get()->toArray();

    expect(Artisan::call('cms:widget-hub-adopt', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-1')->toContain("post {$keyed}");

    expect(DB::table('posts')->where('id', $keyed)->value('content'))->toBe($stored)
        ->and(DB::table('widgets')->orderBy('id')->get()->toArray())->toEqual($rows)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('leaves site widgets alone', function () {
    $site = Widget::query()->forceCreate(['widget_key' => 'site.promo', 'name' => 'Promo', 'origin' => Widget::ORIGIN_SITE]);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);

    expect($site->fresh()->widget_key)->toBe('site.promo');
});

it('ignores hub designs of other projects when comparing', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    $published = adoptServeBundle($this, $widget, 3, [adoptOtherProjectDesign()]);
    expect($published)->not->toBe(adoptContentHash($widget));

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('recorded as downloaded');

    $fresh = $widget->fresh();
    expect($fresh->widget_key)->toBe('example.hero')
        ->and($fresh->origin)->toBe(Widget::ORIGIN_HUB)
        ->and((int) $fresh->version)->toBe(3);
    $audit = AuditLog::query()->where('target_id', $widget->id)->first();
    expect($audit->action)->toBe('widget.downloaded')
        ->and($audit->options['version'])->toBe(3);
});

it('ignores site designs of other projects too', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServeBundle($this, $widget, 2, []);
    WidgetTheme::query()->forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'ofm', 'name' => 'Ofm', 'content' => '<b>{title}</b>',
        'css' => null, 'query' => null, 'project_name' => '["ExampleJournal"]',
    ]);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);

    expect($widget->fresh()->widget_key)->toBe('example.hero')
        ->and((int) $widget->fresh()->version)->toBe(2);
});

it('still converts a widget whose usable designs differ', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServeBundle($this, $widget, 2, [adoptOtherProjectDesign()]);
    WidgetTheme::query()->where('widget_id', $widget->id)->update(['content' => '<section>{title}</section>']);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-1');

    expect($widget->fresh()->widget_key)->toBe('site.example.hero-1')
        ->and($widget->fresh()->origin)->toBe(Widget::ORIGIN_SITE);
});

it('refuses the whole run when a compared bundle is not trusted', function () {
    $first = adoptHubWidget($this, 'example.first');
    $second = adoptHubWidget($this, 'example.second');
    ($this->serveVersions)('example.first', [1 => str_repeat('b', 64)]);
    adoptServeBundle($this, $second, 2, [adoptOtherProjectDesign()], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $rows = DB::table('widgets')->orderBy('id')->get()->toArray();

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(1)
        ->and(Artisan::output())->toContain('nothing was changed');

    expect(DB::table('widgets')->orderBy('id')->get()->toArray())->toEqual($rows)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('a site row that differs from the hub version only in per-language names is that version, not a site copy', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    // The published v1 bundle has no translations (built before they existed).
    $published = adoptServeBundle($this, $widget, 1, []);
    $widget->forceFill(['name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero'], 'description_translations' => ['vi' => 'Băng rôn']])->save();
    $widget->template()->first()->forceFill(['name_translations' => ['vi' => 'Mặc định']])->save();
    expect(adoptContentHash($widget->fresh()))->not->toBe($published);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('recorded as downloaded');

    $fresh = $widget->fresh();
    expect($fresh->widget_key)->toBe('example.hero')
        ->and($fresh->origin)->toBe(Widget::ORIGIN_HUB)
        ->and($fresh->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa']);
});

it('a converted site copy keeps its per-language names with the copy suffix', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServeBundle($this, $widget, 2, []);
    $widget->forceFill(['name_translations' => ['vi' => 'Ảnh bìa']])->save();
    WidgetTheme::query()->where('widget_id', $widget->id)->update(['content' => '<section>{title}</section>']);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);

    expect($widget->fresh()->origin)->toBe(Widget::ORIGIN_SITE)
        ->and($widget->fresh()->name_translations)->toBe(['vi' => 'Ảnh bìa (bản riêng)']);
});

/**
 * Serve `$version` as a bundle built on local: the site's designs listing a client project too, a client-only input,
 * and Vietnamese editor labels in every input's options.
 */
function adoptServeLocalBuiltBundle(object $test, Widget $widget, int $version): string
{
    $site = app(WidgetBundleBuilder::class)->build($widget->widget_key, $version, 'iSPA');
    $withLabel = static function (?string $options, string $label): string {
        $decoded = $options === null ? [] : (json_decode($options, true) ?: []);

        return json_encode($decoded + ['label' => $label], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    $bundle = WidgetBundle::create(
        widgetKey: $site->widgetKey, version: $version, publisher: 'iSPA', widget: $site->widget,
        designs: array_map(static fn (array $d): array => ['project_name' => ['Default', 'ExampleJournal']] + $d, $site->designs),
        inputs: [
            ...array_map(static fn (array $i): array => ['options' => $withLabel($i['options'], 'Tiêu đề')] + $i, $site->inputs),
            ['input_key' => 'location_banner', 'name' => 'location_banner', 'type' => 'image', 'default_value' => null, 'options' => $withLabel('{"alt":"Image"}', 'Ảnh')],
        ],
        assets: [], requires: $site->requires,
    );
    $test->hub[WIDGET_ADOPT_PATH . '/' . $widget->widget_key . '/versions/' . $version] = [200, [
        'sig_json' => (new PackageSigner())->envelope($bundle->toCanonicalJson(), $test->secretKey),
    ]];
    ($test->serveVersions)($widget->widget_key, [$version => $bundle->contentSha256]);

    return $bundle->contentSha256;
}

it('ignores client project lists, client-only inputs and editor labels when matching a hub version', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    // Older sites store empty options as "[]" (labels were never seeded into them).
    WidgetInput::query()->forceCreate([
        'widget_id' => $widget->id, 'input_key' => 'photo', 'name' => 'photo', 'type' => 'image',
        'default_value' => null, 'options' => ['class' => 'w-full', 'alt' => 'Image'],
    ]);
    WidgetInput::query()->where('widget_id', $widget->id)->where('input_key', 'title')->update(['options' => '[]']);
    $published = adoptServeLocalBuiltBundle($this, $widget->fresh(), 2);
    expect($published)->not->toBe(adoptContentHash($widget->fresh()));

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('recorded as downloaded');

    $fresh = $widget->fresh();
    expect($fresh->widget_key)->toBe('example.hero')
        ->and($fresh->origin)->toBe(Widget::ORIGIN_HUB)
        ->and((int) $fresh->version)->toBe(2);
});

it('still converts when design content differs from a bundle built on local', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServeLocalBuiltBundle($this, $widget, 2);
    WidgetTheme::query()->where('widget_id', $widget->id)->update(['content' => '<section>{title}</section>']);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-1');

    expect($widget->fresh()->origin)->toBe(Widget::ORIGIN_SITE);
});

it('still converts when the site has an input the hub version lacks', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServeLocalBuiltBundle($this, $widget, 2);
    WidgetInput::query()->forceCreate([
        'widget_id' => $widget->id, 'input_key' => 'extra', 'name' => 'extra', 'type' => 'text', 'default_value' => null, 'options' => null,
    ]);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('site.example.hero-1');
});

/**
 * Serve `$version` as the site's rows plus hub names: widget and design `name_translations`, a widget
 * `description_translations`, and a Vietnamese editor `label` merged into every input's options.
 */
function adoptServeNamedBundle(object $test, Widget $widget, int $version): string
{
    $site = app(WidgetBundleBuilder::class)->build($widget->widget_key, $version, 'iSPA');
    $bundle = WidgetBundle::create(
        widgetKey: $site->widgetKey, version: $version, publisher: 'iSPA',
        widget: ['name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero'], 'description_translations' => ['vi' => 'Băng rôn đầu trang']] + $site->widget,
        designs: array_map(static fn (array $d): array => ['name_translations' => ['vi' => 'Mặc định']] + $d, $site->designs),
        inputs: array_map(static function (array $i): array {
            $decoded = $i['options'] === null ? [] : (json_decode($i['options'], true) ?: []);

            return ['options' => json_encode($decoded + ['label' => 'Tiêu đề'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] + $i;
        }, $site->inputs),
        assets: [], requires: $site->requires,
    );
    $test->hub[WIDGET_ADOPT_PATH . '/' . $widget->widget_key . '/versions/' . $version] = [200, [
        'sig_json' => (new PackageSigner())->envelope($bundle->toCanonicalJson(), $test->secretKey),
    ]];
    ($test->serveVersions)($widget->widget_key, [$version => $bundle->contentSha256]);

    return $bundle->contentSha256;
}

/** Render-relevant columns of the widget, its designs and inputs (input options without the editor label). */
function adoptRenderState(Widget $widget): array
{
    return [
        DB::table('widgets')->where('id', $widget->id)->first(['widget_key', 'name', 'description', 'js', 'origin', 'publisher']),
        DB::table('widget_themes')->where('widget_id', $widget->id)->orderBy('id')->get(['id', 'theme_key', 'name', 'content', 'css', 'query', 'project_name'])->all(),
        DB::table('widget_inputs')->where('widget_id', $widget->id)->orderBy('id')->get(['id', 'input_key', 'name', 'type', 'default_value', 'options'])
            ->map(static function (object $row): array {
                $options = json_decode((string) $row->options, true) ?: [];
                unset($options['label']);

                return ['options' => $options] + (array) $row;
            })->all(),
    ];
}

it('stamping copies the hub names and editor labels and leaves render fields alone', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    WidgetInput::query()->forceCreate([
        'widget_id' => $widget->id, 'input_key' => 'photo', 'name' => 'photo', 'type' => 'image',
        'default_value' => null, 'options' => ['class' => 'w-full', 'alt' => 'Image'],
    ]);
    $published = adoptServeNamedBundle($this, $widget->fresh(), 2);
    $before = adoptRenderState($widget);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('hub names/labels copied');

    $fresh = $widget->fresh();
    $labels = WidgetInput::query()->where('widget_id', $widget->id)->orderBy('input_key')->get()
        ->mapWithKeys(static fn (WidgetInput $i): array => [$i->input_key => json_decode($i->getRawOriginal('options'), true)])->all();
    expect((int) $fresh->version)->toBe(2)
        ->and($fresh->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($fresh->description_translations)->toBe(['vi' => 'Băng rôn đầu trang'])
        ->and($fresh->template()->first()->name_translations)->toBe(['vi' => 'Mặc định'])
        ->and($labels['title'])->toBe(['label' => 'Tiêu đề'])
        ->and($labels['photo'])->toBe(['class' => 'w-full', 'alt' => 'Image', 'label' => 'Tiêu đề'])
        ->and(adoptRenderState($widget))->toEqual($before)
        ->and(adoptContentHash($fresh))->toBe($published)
        ->and(AuditLog::query()->where('action', 'widget.downloaded')->count())->toBe(1);
});

it('a re-run backfills names and labels on a row already stamped at that version, once', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    WidgetInput::query()->where('widget_id', $widget->id)->update(['options' => '{"max":"80","placeholder":"x"}']);
    adoptServeNamedBundle($this, $widget->fresh(), 2);
    // Stored with other spacing: still the same options, so they match and only the label is added.
    WidgetInput::query()->where('widget_id', $widget->id)->update(['options' => '{"max": "80", "placeholder": "x"}']);
    DB::table('widgets')->where('id', $widget->id)->update(['version' => 2]);
    $before = adoptRenderState($widget);

    expect(Artisan::call('cms:widget-hub-adopt', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('hub names/labels would be copied');
    expect($widget->fresh()->name_translations)->toBeNull();

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('hub names/labels copied');
    $fresh = $widget->fresh();
    expect($fresh->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($fresh->template()->first()->name_translations)->toBe(['vi' => 'Mặc định'])
        ->and(json_decode(WidgetInput::query()->where('widget_id', $widget->id)->first()->getRawOriginal('options'), true))
        ->toEqual(['max' => '80', 'placeholder' => 'x', 'label' => 'Tiêu đề'])
        ->and(adoptRenderState($widget))->toEqual($before)
        ->and(AuditLog::query()->count())->toBe(0);

    $rows = DB::table('widget_inputs')->orderBy('id')->get()->toArray();
    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->not->toContain('names/labels')
        ->and(DB::table('widget_inputs')->orderBy('id')->get()->toArray())->toEqual($rows)
        ->and(AuditLog::query()->count())->toBe(0);
});

const WIDGET_ADOPT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** Serve version `$version` of the widget with a preview PNG on its default design (content equals the site rows). */
function adoptServePreviewBundle(object $test, Widget $widget, int $version): void
{
    $site = app(WidgetBundleBuilder::class)->build($widget->widget_key, $version, 'iSPA');
    $designs = array_map(static fn (array $d): array => ['preview_png_base64' => WIDGET_ADOPT_PNG] + $d, $site->designs);
    $bundle = WidgetBundle::create(
        widgetKey: $site->widgetKey, version: $version, publisher: 'iSPA', widget: $site->widget,
        designs: $designs, inputs: $site->inputs, assets: [], requires: $site->requires,
    );
    $test->hub[WIDGET_ADOPT_PATH . '/' . $widget->widget_key . '/versions/' . $version] = [200, [
        'sig_json' => (new PackageSigner())->envelope($bundle->toCanonicalJson(), $test->secretKey),
    ]];
    ($test->serveVersions)($widget->widget_key, [$version => $bundle->contentSha256]);
}

it('dry run counts preview images, a real run writes them for stamped widgets, once', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    DB::table('widgets')->where('id', $widget->id)->update(['version' => 2]);
    adoptServePreviewBundle($this, $widget->fresh(), 2);
    $disk = Illuminate\Support\Facades\Storage::fake('public');

    expect(Artisan::call('cms:widget-hub-adopt', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('1 preview image(s) would be filled');
    expect($widget->template()->first()->preview_image)->toBeNull();

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->toContain('1 preview image(s) filled');
    $theme = $widget->template()->first();
    expect($theme->preview_image)->toBe('widget-previews/example.hero/default-v2.png')
        ->and((bool) $theme->preview_stale)->toBeFalse();
    $disk->assertExists('widget-previews/example.hero/default-v2.png');
    expect(count($disk->allFiles()))->toBe(1);

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0)
        ->and(Artisan::output())->not->toContain('preview image');
});

it('stamping an unstamped widget also brings the preview image, and keeps an existing preview', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    $other = adoptHubWidget($this, 'example.kept');
    WidgetTheme::query()->where('widget_id', $other->id)->update(['preview_image' => 'widget-previews/own/custom.png']);
    adoptServePreviewBundle($this, $widget->fresh(), 1);
    adoptServePreviewBundle($this, $other->fresh(), 1);
    $disk = Illuminate\Support\Facades\Storage::fake('public');

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);
    expect($widget->template()->first()->preview_image)->toBe('widget-previews/example.hero/default-v1.png')
        ->and($other->template()->first()->preview_image)->toBe('widget-previews/own/custom.png');
    $disk->assertMissing('widget-previews/example.kept/default-v1.png');
});

it('does not give preview images to site copies', function () {
    $widget = adoptHubWidget($this, 'example.hero');
    adoptServePreviewBundle($this, $widget->fresh(), 1);
    DB::table('widgets')->where('id', $widget->id)->update(['origin' => Widget::ORIGIN_SITE]);
    $disk = Illuminate\Support\Facades\Storage::fake('public');

    expect(Artisan::call('cms:widget-hub-adopt'))->toBe(0);
    expect($widget->template()->first()->preview_image)->toBeNull()
        ->and($disk->allFiles())->toBe([]);
});
