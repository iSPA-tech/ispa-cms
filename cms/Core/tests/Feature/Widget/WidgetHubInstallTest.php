<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Widget hub distribution, Task 5: a site downloads and updates hub widgets. The hub is faked by binding a
 * PlatformHubClient pointed at https://hub.test and answering its requests (signed like the real hub) with bundles
 * signed by a test key that the test trusts.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Widget\Hub\WidgetHubInstaller;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

const WIDGET_HUB_BASE = '/api/v2/admin/widget';
const WIDGET_HUB_SECRET = 'hub-install-test-secret';
const WIDGET_HUB_PATH = '/api/v2/public/platform/widgets';

function widgetHubPngBytes(): string
{
    return "\x89PNG\r\n\x1a\n" . 'preview';
}

/**
 * A signed bundle of `example.hero` with the given designs (theme_key => content) and inputs (input_key => name).
 *
 * @param array<string, string> $designs
 * @param array<string, string> $inputs
 * @param array<string, int>    $resolvers
 */
function widgetHubSigned(string $secretKey, int $version, array $designs, array $inputs = ['title' => 'Tiêu đề'], array $resolvers = []): string
{
    $designRows = [];
    foreach ($designs as $themeKey => $content) {
        $designRows[] = [
            'theme_key' => $themeKey, 'name' => ucfirst($themeKey), 'content' => $content, 'css' => '.hero{color:red}',
            'query' => [], 'project_name' => ['Default'], 'preview_png_base64' => base64_encode(widgetHubPngBytes()),
        ];
    }
    $inputRows = [];
    foreach ($inputs as $inputKey => $name) {
        $inputRows[] = [
            'input_key' => $inputKey, 'name' => $name, 'type' => 'text', 'default_value' => 'Xin chào',
            'options' => '{"is_background":false}',
        ];
    }
    $bundle = WidgetBundle::create(
        widgetKey: 'example.hero',
        version: $version,
        publisher: 'iSPA',
        widget: ['name' => 'Hero', 'description' => 'Big banner', 'js' => null],
        designs: $designRows,
        inputs: $inputRows,
        assets: [],
        requires: ['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION, 'resolvers' => $resolvers],
    );

    return (new PackageSigner())->envelope($bundle->toCanonicalJson(), $secretKey);
}

beforeEach(function () {
    URL::forceScheme('https');
    config()->set('ispa-cms.folder_name', 'Default');
    config(['ispa-cms.widget.catalog_authoring' => false, 'ispa-cms.widget.hub_lock_wait_seconds' => 1]);

    // Generated design files go to a temporary storage tree, preview files to a fake disk.
    $this->root = sys_get_temp_dir() . '/ispa-widget-hub-install-' . uniqid('', true);
    File::ensureDirectoryExists($this->root . '/storage/app');
    $this->app->useStoragePath($this->root . '/storage');
    Storage::fake('public');

    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $this->secretKey = sodium_crypto_sign_secretkey($pair);
    config([
        'core.module_packages.trusted_keys' => [KeyId::fromPublicKey($public) => base64_encode($public)],
        'core.module_packages.widget_bundle_key_ids' => [KeyId::fromPublicKey($public)],
        'core.module_packages.revoked_key_ids' => [],
    ]);

    // The fake hub: path => [status, json body]; `onBundle` runs when a bundle is served.
    $this->hub = [];
    $this->hubRequests = [];
    $this->onBundle = null;
    $this->app->instance(PlatformHubClient::class, new PlatformHubClient('site.test', WIDGET_HUB_SECRET, 'https://hub.test'));
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->hubRequests[] = $path;
        [$status, $payload] = $this->hub[$path] ?? [404, ['error_code' => 'WIDGET_HUB_NOT_FOUND']];
        if (str_contains($path, '/versions/') && $this->onBundle !== null) {
            ($this->onBundle)();
        }
        // A string payload is served as raw bytes (preview images), anything else as JSON.
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = (string) ($request->header(PlatformSignature::HEADER_NONCE)[0] ?? '');

        return Http::response($body, $status, [
            'Content-Type' => is_string($payload) ? 'image/png' : 'application/json',
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response(WIDGET_HUB_SECRET, $status, $nonce, $body),
        ]);
    });

    // Serve versions of example.hero: version => sig.json (null = list only).
    $this->serve = function (array $versions, array $catalogEntry = []): void {
        krsort($versions);
        $this->hub[WIDGET_HUB_PATH . '/example.hero/versions'] = [200, ['versions' => array_map(
            static fn (int $v): array => ['version' => $v, 'content_sha256' => str_repeat('a', 64)],
            array_keys($versions),
        )]];
        foreach ($versions as $version => $sig) {
            $this->hub[WIDGET_HUB_PATH . '/example.hero/versions/' . $version] = [200, ['widget_key' => 'example.hero', 'version' => $version, 'sig_json' => $sig]];
        }
        $this->hub[WIDGET_HUB_PATH] = [200, ['widgets' => [array_merge([
            'widget_key' => 'example.hero', 'publisher' => 'iSPA', 'name' => 'Hero', 'description' => 'Big banner',
            'latest_version' => max(array_keys($versions)), 'content_sha256' => str_repeat('a', 64),
            'requires' => ['renderer' => 1, 'resolvers' => []], 'preview_theme_keys' => ['default'],
        ], $catalogEntry)]]];
    };

    $makeUser = function (string $email, array $actions): User {
        $ids = array_map(
            static fn (string $action): int => Permission::query()->firstOrCreate(['group' => 'widget', 'name' => $action], ['title' => "widget $action"])->id,
            $actions,
        );
        $user = User::query()->create(['name' => $email, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['type' => UserType::ADMIN])->save();
        $role = Role::query()->create(['name' => $email, 'user_id' => $user->id]);
        $role->permissions()->attach($ids);
        $user->roles()->attach($role->id);

        return $user;
    };
    $this->admin = $makeUser('hub-admin@test.local', ['viewAny', 'view', 'update', 'hub']);
    $this->viewer = $makeUser('hub-viewer@test.local', ['viewAny', 'view']);

    DB::table('languages')->insert([
        'id' => 1, 'name' => 'Vietnamese', 'path' => 'vi', 'path_alias' => 'vi', 'currency' => 'VND', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->makePost = function (string $name, array $content): Post {
        $post = new Post();
        $post->forceFill(['name' => $name, 'type' => 'page', 'status' => null, 'language_id' => 1, 'content' => $content])->save();

        return $post->fresh();
    };

    $this->counts = static fn (): array => [
        Widget::query()->count(), WidgetTheme::query()->count(), WidgetInput::query()->count(), AuditLog::query()->count(),
    ];
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('download installs widget designs inputs and previews', function () {
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>', 'wide' => '<div>{title}</div>'])]);
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk()
        ->assertJsonPath('message', 'Đã tải widget về site.');

    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    $response->assertJsonPath('data.id', $widget->id);
    expect($widget->only(['name', 'description', 'origin', 'publisher', 'version']))->toBe([
        'name' => 'Hero', 'description' => 'Big banner', 'origin' => 'hub', 'publisher' => 'iSPA', 'version' => 2,
    ]);

    $themes = $widget->template()->orderBy('theme_key')->get();
    expect($themes->pluck('theme_key')->all())->toBe(['default', 'wide'])
        ->and($themes[0]->content)->toBe('<section>{title}</section>')
        ->and($themes[0]->project_name)->toBe(['Default'])
        ->and($themes[0]->getRawOriginal('query'))->toBe('[]')
        ->and((bool) $themes[0]->preview_stale)->toBeFalse()
        ->and($themes[0]->preview_image)->toBe('widget-previews/example.hero/default-v2.png');
    Storage::disk('public')->assertExists('widget-previews/example.hero/default-v2.png');
    Storage::disk('public')->assertExists('widget-previews/example.hero/wide-v2.png');
    expect(Storage::disk('public')->get('widget-previews/example.hero/default-v2.png'))->toBe(widgetHubPngBytes());

    // options travels raw and is stored raw (not double encoded).
    $input = $widget->inputs()->sole();
    expect($input->input_key)->toBe('title')
        ->and($input->name)->toBe('Tiêu đề')
        ->and($input->getRawOriginal('options'))->toBe('{"is_background":false}')
        ->and($input->options)->toBe(['is_background' => false]);

    $audit = AuditLog::query()->where('action', 'widget.downloaded')->sole();
    expect($audit->target_id)->toBe($widget->id)->and($audit->user_id)->toBe($this->admin->id);

    // The design files were regenerated after commit.
    $response->assertJsonPath('data.sync_status', 'ready');
    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($manifest)->not->toBeNull()
        ->and(LegacyWidgetArtifactSetPublisher::resolveProjectFilePath(
            $manifest, 'Default', "widget_{$widget->id}_theme_{$themes[0]->id}.blade.php"
        ))->not->toBeNull();
});

it('install of a tampered bundle leaves the database unchanged', function () {
    $sig = json_decode(widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>']), true);
    $manifest = base64_decode($sig['manifest'], true);
    $sig['manifest'] = base64_encode(str_replace('<section>', '<sectiom>', $manifest));
    ($this->serve)([2 => json_encode($sig)]);
    $before = ($this->counts)();
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(422)
        ->assertJsonPath('error_code', 'WIDGET_BUNDLE_UNTRUSTED')
        ->assertJsonPath('message', 'Gói widget không hợp lệ nên chưa được cài.');

    expect(($this->counts)())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('download refuses a bundle signed with the hub countersign key and writes nothing', function () {
    $pair = sodium_crypto_sign_keypair();
    $hubPublic = sodium_crypto_sign_publickey($pair);
    $hubId = KeyId::fromPublicKey($hubPublic);
    config([
        'core.module_packages.trusted_keys' => config('core.module_packages.trusted_keys') + [$hubId => base64_encode($hubPublic)],
        'core.module_packages.countersign_key_ids' => [$hubId],
        'core.module_packages.widget_bundle_key_ids' => [...config('core.module_packages.widget_bundle_key_ids'), $hubId],
    ]);
    ($this->serve)([2 => widgetHubSigned(sodium_crypto_sign_secretkey($pair), 2, ['default' => '<section>{title}</section>'])]);
    $before = ($this->counts)();
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(422)
        ->assertJsonPath('error_code', 'WIDGET_BUNDLE_UNTRUSTED');
    expect(($this->counts)())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('the authoring copy refuses hub download, update and update impact with WIDGET_AUTHORING_COPY', function () {
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>']), 2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>'])]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    $widget->forceFill(['version' => 1])->save();
    $before = ($this->counts)();
    $requests = count($this->hubRequests);

    config(['ispa-cms.widget.catalog_authoring' => true]);
    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_AUTHORING_COPY');
    $this->getJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update/impact")->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_AUTHORING_COPY');
    $widget->delete();
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_AUTHORING_COPY');

    expect(count($this->hubRequests))->toBe($requests);
});

it('download refuses missing resolver with WIDGET_RESOLVER_MISSING', function () {
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p></p>'], resolvers: ['provider:nowhere.feed' => 1])]);
    $before = ($this->counts)();
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_RESOLVER_MISSING')
        ->assertJsonPath('message', 'Widget này cần tính năng mà site chưa có. Hãy cập nhật hệ thống trước.');

    expect(($this->counts)())->toBe($before);
});

it('download refuses a taken key', function () {
    $local = Widget::query()->forceCreate(['widget_key' => 'example.hero', 'name' => 'Local hero', 'origin' => Widget::ORIGIN_SITE]);
    WidgetTheme::query()->forceCreate(['widget_id' => $local->id, 'theme_key' => 'default', 'name' => 'Mine', 'content' => '<b>mine</b>']);
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>'])]);
    $before = ($this->counts)();
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN')
        ->assertJsonPath('message', 'Site đã có một widget trùng mã này.');

    expect(($this->counts)())->toBe($before)
        ->and($local->fresh()->only(['name', 'origin']))->toBe(['name' => 'Local hero', 'origin' => 'site'])
        ->and($local->template()->sole()->content)->toBe('<b>mine</b>');
});

it('concurrent download installs once', function () {
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>', 'wide' => '<div></div>'])]);
    Sanctum::actingAs($this->admin);

    // Second admin presses "Tải về" while the first request is fetching the bundle (and holds the widget lock):
    // it waits, then gets the key-taken answer, never a 500, and touches no file.
    $second = null;
    $this->onBundle = function () use (&$second): void {
        $this->onBundle = null;
        $second = $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download');
    };
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $second->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN');

    // And a plain second press after the first finished.
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN');

    expect(Widget::query()->where('widget_key', 'example.hero')->count())->toBe(1);
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    expect($widget->template()->count())->toBe(2)
        ->and($widget->inputs()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'widget.downloaded')->count())->toBe(1);
    // The winner's preview files survive the losing requests; no temporary file is left behind.
    Storage::disk('public')->assertExists('widget-previews/example.hero/default-v2.png');
    Storage::disk('public')->assertExists('widget-previews/example.hero/wide-v2.png');
    expect(Storage::disk('public')->allFiles())->toBe([
        'widget-previews/example.hero/default-v2.png', 'widget-previews/example.hero/wide-v2.png',
    ]);
});

it('a row written during the download is caught inside the transaction and keeps its files', function () {
    // A writer that does not take the widget lock (a console command, another tool) creates the key mid-download.
    Storage::disk('public')->put('widget-previews/example.hero/default-v2.png', 'theirs');
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>'])]);
    $this->onBundle = function (): void {
        $this->onBundle = null;
        Widget::query()->forceCreate(['widget_key' => 'example.hero', 'name' => 'Other', 'origin' => Widget::ORIGIN_SITE]);
    };
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN');

    expect(Storage::disk('public')->get('widget-previews/example.hero/default-v2.png'))->toBe('theirs')
        ->and(Storage::disk('public')->allFiles())->toBe(['widget-previews/example.hero/default-v2.png']);
});

it('a unique key violation on insert answers WIDGET_KEY_TAKEN', function () {
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<section>{title}</section>'])]);
    // The row appears between the locked read and the insert.
    Widget::creating(static function (Widget $widget): void {
        if ($widget->widget_key === 'example.hero' && $widget->name === 'Hero') {
            DB::table('widgets')->insert(['widget_key' => 'example.hero', 'name' => 'Racer', 'origin' => 'site', 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('a request that cannot get the widget lock answers key taken or up to date', function () {
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>'])]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>'])]);

    $lock = Cache::lock(WidgetHubInstaller::lockName('example.hero'), 30);
    expect($lock->get())->toBeTrue();
    $requests = count($this->hubRequests);
    try {
        $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(409)
            ->assertJsonPath('error_code', 'WIDGET_KEY_TAKEN');
        $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertStatus(409)
            ->assertJsonPath('error_code', 'WIDGET_UP_TO_DATE');
    } finally {
        $lock->release();
    }

    expect(count($this->hubRequests))->toBe($requests)
        ->and($widget->fresh()->version)->toBe(1);
    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertOk();
    expect($widget->fresh()->version)->toBe(2);
});

it('update keeps design ids and refuses removed design in use', function () {
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>', 'old' => '<p>old</p>'], ['title' => 'Tiêu đề', 'gone' => 'Bỏ'])]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    $default = $widget->template()->where('theme_key', 'default')->sole();
    $title = $widget->inputs()->where('input_key', 'title')->sole();
    // A blocked widget stays blocked through an update.
    $widget->forceFill(['blocked_at' => now(), 'blocked_by' => $this->admin->id, 'blocked_reason' => 'broken'])->save();
    ($this->makePost)('Home', [['type' => 'widget', 'widget_id' => $widget->id, 'template_id' => $default->id, 'value' => ['title' => 'x']]]);

    // v2: default changed, old removed (unused), wide added, input gone removed, subtitle added.
    ($this->serve)([
        1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>', 'old' => '<p>old</p>']),
        2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>', 'wide' => '<div>v2</div>'], ['subtitle' => 'Phụ đề', 'title' => 'Tiêu đề mới']),
    ]);

    $this->getJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update/impact")->assertOk()
        ->assertJsonPath('data.pages', 1)
        ->assertJsonPath('data.areas', 0)
        ->assertJsonPath('data.removed_designs_in_use', [])
        ->assertJsonPath('data.new_input_labels', ['Phụ đề']);

    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertOk()
        ->assertJsonPath('message', 'Đã cập nhật widget.');

    $widget->refresh();
    expect($widget->version)->toBe(2)
        ->and($widget->isBlocked())->toBeTrue()
        ->and($widget->template()->orderBy('theme_key')->pluck('theme_key')->all())->toBe(['default', 'wide'])
        ->and($widget->template()->where('theme_key', 'default')->sole()->id)->toBe($default->id)
        ->and($default->fresh()->content)->toBe('<p>v2</p>')
        ->and($default->fresh()->preview_image)->toBe('widget-previews/example.hero/default-v2.png')
        ->and($widget->inputs()->orderBy('input_key')->pluck('input_key')->all())->toBe(['subtitle', 'title'])
        ->and($widget->inputs()->where('input_key', 'title')->sole()->id)->toBe($title->id)
        ->and($title->fresh()->name)->toBe('Tiêu đề mới')
        ->and(AuditLog::query()->where('action', 'widget.updated')->count())->toBe(1);

    // v3 drops the design the page uses: refused, nothing changes.
    ($this->serve)([3 => widgetHubSigned($this->secretKey, 3, ['wide' => '<div>v3</div>'])]);
    $before = ($this->counts)();

    $this->getJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update/impact")->assertOk()
        ->assertJsonPath('data.removed_designs_in_use', ['Default']);
    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_UPDATE_DESIGN_IN_USE')
        ->assertJsonPath('message', 'Bản mới bỏ một thiết kế đang được dùng, nên chưa cập nhật được.');

    expect(($this->counts)())->toBe($before)
        ->and($widget->fresh()->version)->toBe(2)
        ->and($default->fresh()->content)->toBe('<p>v2</p>')
        ->and(Storage::disk('public')->exists('widget-previews/example.hero/wide-v3.png'))->toBeFalse();
});

it('update refuses an older bundle served for a newer version', function () {
    ($this->serve)([3 => widgetHubSigned($this->secretKey, 3, ['default' => '<p>v3</p>'])]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();

    // The hub says 4 exists but serves a validly signed version 2 (replay of an old bundle).
    ($this->serve)([4 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>'])]);
    $before = ($this->counts)();

    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertStatus(422)
        ->assertJsonPath('error_code', 'WIDGET_BUNDLE_UNTRUSTED');

    // Nothing newer than what is installed: refused too.
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>'])]);
    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_UP_TO_DATE');

    expect(($this->counts)())->toBe($before)
        ->and($widget->fresh()->version)->toBe(3)
        ->and($widget->template()->sole()->content)->toBe('<p>v3</p>');
});

it('update refuses a site copy', function () {
    $copy = Widget::query()->forceCreate([
        'widget_key' => 'site.example.hero-1', 'name' => 'Hero (bản riêng)', 'origin' => Widget::ORIGIN_SITE,
        'cloned_from_widget_key' => 'example.hero',
    ]);
    ($this->serve)([2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>v2</p>'])]);
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . "/{$copy->id}/hub-update")->assertStatus(409)
        ->assertJsonPath('error_code', 'WIDGET_SITE_COPY');
    $this->postJson(WIDGET_HUB_BASE . '/999999/hub-update')->assertStatus(404)
        ->assertJsonPath('error_code', 'WIDGET_NOT_FOUND');

    expect($copy->fresh()->version)->toBe(1)
        ->and($this->hubRequests)->toBe([]);
});

it('catalog read failure returns 503 WIDGET_HUB_UNREACHABLE and site list still loads', function () {
    $this->hub[WIDGET_HUB_PATH] = [500, ['message' => 'boom']];
    Sanctum::actingAs($this->admin);

    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertStatus(503)
        ->assertJsonPath('error_code', 'WIDGET_HUB_UNREACHABLE')
        ->assertJsonPath('message', 'Chưa kết nối được kho widget. Widget trên site vẫn dùng bình thường.');

    $local = Widget::query()->forceCreate(['widget_key' => 'site.local', 'name' => 'Local', 'origin' => Widget::ORIGIN_SITE]);
    $requests = count($this->hubRequests);
    $row = collect($this->getJson(WIDGET_HUB_BASE . '?scope=library')->assertOk()->json('data.data'))->firstWhere('id', $local->id);
    expect($row)->not->toBeNull()
        ->and($row['hub_latest_version'])->toBeNull()
        ->and(count($this->hubRequests))->toBe($requests);
});

it('catalog lists hub widgets with install state and the library shows the cached latest version', function () {
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>'])]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();
    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();

    ($this->serve)([1 => null, 2 => null], ['requires' => ['renderer' => 1, 'resolvers' => ['provider:nowhere.feed' => 1]]]);
    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog?refresh=1')->assertOk()
        ->assertJsonPath('data.0.widget_key', 'example.hero')
        ->assertJsonPath('data.0.latest_version', 2)
        ->assertJsonPath('data.0.installed_widget_id', $widget->id)
        ->assertJsonPath('data.0.installed_version', 1)
        ->assertJsonPath('data.0.missing_resolvers', ['provider:nowhere.feed']);

    // Cached for the library list: no hub call while listing.
    $requests = count($this->hubRequests);
    $row = collect($this->getJson(WIDGET_HUB_BASE . '?scope=library')->assertOk()->json('data.data'))->firstWhere('id', $widget->id);
    expect($row['hub_latest_version'])->toBe(2)
        ->and(count($this->hubRequests))->toBe($requests);
    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk();
    expect(count($this->hubRequests))->toBe($requests);
    expect(app(WidgetHubCatalog::class)->cachedLatestVersions())->toBe(['example.hero' => 2]);

    // The library list (admin-list) names each design by theme_key so the update dialog can pair it with the hub version.
    $row = collect($this->getJson(WIDGET_HUB_BASE . '/admin-list?scope=library')->assertOk()->json('data.data'))->firstWhere('id', $widget->id);
    expect($row['template'][0]['theme_key'])->toBe('default');
});

it('hub routes need the widget.hub permission', function () {
    Sanctum::actingAs($this->viewer);

    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertStatus(403);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertStatus(403);
    expect($this->hubRequests)->toBe([]);
});

function widgetHubRealPng(int $width = 40, int $height = 30): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

it('hub preview proxy serves the PNG and caches it per site key version and theme', function () {
    $png = widgetHubRealPng();
    $this->hub[WIDGET_HUB_PATH . '/example.hero/previews/2/default'] = [200, $png];
    Sanctum::actingAs($this->admin);

    $response = $this->get(WIDGET_HUB_BASE . '/hub/example.hero/previews/2/default');
    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->getContent())->toBe($png)
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=86400')
        ->and($this->hubRequests)->toBe([WIDGET_HUB_PATH . '/example.hero/previews/2/default']);

    // Second read comes from the cache.
    expect($this->get(WIDGET_HUB_BASE . '/hub/example.hero/previews/2/default')->assertOk()->getContent())->toBe($png)
        ->and($this->hubRequests)->toHaveCount(1);

    // Another site sharing the cache store does not see it.
    config(['app.url' => 'https://other-site.test']);
    $this->get(WIDGET_HUB_BASE . '/hub/example.hero/previews/2/default')->assertOk();
    expect($this->hubRequests)->toHaveCount(2);
});

it('hub preview proxy answers 404 WIDGET_HUB_NOT_FOUND and 503 WIDGET_HUB_UNREACHABLE', function () {
    Sanctum::actingAs($this->admin);

    $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/missing')->assertStatus(404)
        ->assertJsonPath('error_code', 'WIDGET_HUB_NOT_FOUND');

    $this->hub[WIDGET_HUB_PATH . '/example.hero/previews/1/default'] = [500, ['message' => 'boom']];
    $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/default')->assertStatus(503)
        ->assertJsonPath('error_code', 'WIDGET_HUB_UNREACHABLE');
});

it('hub preview proxy refuses bytes that are not a real PNG or are too big, and caches nothing', function () {
    Sanctum::actingAs($this->admin);
    $path = WIDGET_HUB_PATH . '/example.hero/previews/1/default';

    foreach (["\x89PNG\r\n\x1a\nnot an image", '<svg xmlns="http://www.w3.org/2000/svg"/>', widgetHubRealPng() . str_repeat("\0", WidgetBundle::PREVIEW_MAX_BYTES)] as $bytes) {
        $this->hub[$path] = [200, $bytes];
        $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/default')->assertStatus(503)
            ->assertJsonPath('error_code', 'WIDGET_HUB_UNREACHABLE');
    }

    $this->hub[$path] = [200, widgetHubRealPng()];
    $this->get(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/default')->assertOk();
});

it('hub preview proxy needs widget.hub and checks the key and theme shape before calling the hub', function () {
    Sanctum::actingAs($this->viewer);
    $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/default')->assertStatus(403);

    Sanctum::actingAs($this->admin);
    $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/..')->assertStatus(404);
    $this->getJson(WIDGET_HUB_BASE . '/hub/example.hero/previews/0/default')->assertStatus(404);
    expect(fn () => app(WidgetHubCatalog::class)->previewImage('example.hero', 1, '..'))
        ->toThrow(\Cms\Core\Services\Widget\Hub\WidgetHubException::class);
    expect($this->hubRequests)->toBe([]);
});

it('catalog entries carry a site preview URL per preview theme', function () {
    ($this->serve)([1 => null], ['preview_theme_keys' => ['default', 'dark']]);
    Sanctum::actingAs($this->admin);

    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk()
        ->assertJsonPath('data.0.preview_urls.default', url(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/default'))
        ->assertJsonPath('data.0.preview_urls.dark', url(WIDGET_HUB_BASE . '/hub/example.hero/previews/1/dark'));
});

it('safeName never yields a dot segment or a path', function () {
    foreach (['..', '.', '../x', '.hidden', '', 'a/../b'] as $value) {
        $name = WidgetHubInstaller::safeName($value);
        expect($name)->toMatch('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/')->not->toContain('/');
    }
    expect(WidgetHubInstaller::safeName('example.hero'))->toBe('example.hero')
        ->and(WidgetHubInstaller::safeName('my theme'))->toBe('my-theme');
});

/**
 * A signed example.hero bundle whose widget and `default` design carry per-language names.
 */
function widgetHubSignedWithNames(string $secretKey, int $version): string
{
    $bundle = WidgetBundle::create(
        widgetKey: 'example.hero',
        version: $version,
        publisher: 'iSPA',
        widget: [
            'name' => 'Hero', 'description' => 'Big banner', 'js' => null,
            'name_translations' => ['en' => 'Hero', 'vi' => 'Ảnh bìa'],
            'description_translations' => ['vi' => 'Băng rôn lớn'],
        ],
        designs: [[
            'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>{title}</p>', 'css' => null,
            'query' => [], 'project_name' => ['Default'], 'preview_png_base64' => null,
            'name_translations' => ['vi' => 'Mặc định'],
        ]],
        inputs: [['input_key' => 'title', 'name' => 'title', 'type' => 'text', 'default_value' => null, 'options' => null]],
        assets: [],
        requires: ['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION, 'resolvers' => []],
    );

    return (new PackageSigner())->envelope($bundle->toCanonicalJson(), $secretKey);
}

it('a published v1 bundle without per-language names still installs, with none', function () {
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>{title}</p>'])]);
    Sanctum::actingAs($this->admin);

    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();

    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    expect($widget->name_translations)->toBeNull()
        ->and($widget->description_translations)->toBeNull()
        ->and($widget->template()->sole()->name_translations)->toBeNull();
});

it('download writes the per-language names of the bundle, and an update without them clears them', function () {
    ($this->serve)([1 => widgetHubSignedWithNames($this->secretKey, 1)]);
    Sanctum::actingAs($this->admin);
    $this->postJson(WIDGET_HUB_BASE . '/hub/example.hero/download')->assertOk();

    $widget = Widget::query()->where('widget_key', 'example.hero')->sole();
    expect($widget->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($widget->description_translations)->toBe(['vi' => 'Băng rôn lớn'])
        ->and($widget->template()->sole()->name_translations)->toBe(['vi' => 'Mặc định']);

    ($this->serve)([
        1 => widgetHubSignedWithNames($this->secretKey, 1),
        2 => widgetHubSigned($this->secretKey, 2, ['default' => '<p>{title}</p>']),
    ]);
    $this->postJson(WIDGET_HUB_BASE . "/{$widget->id}/hub-update")->assertOk();

    $widget->refresh();
    expect($widget->version)->toBe(2)
        ->and($widget->name_translations)->toBeNull()
        ->and($widget->template()->sole()->name_translations)->toBeNull();
});

it('catalog entries carry the hub per-language names and display_name in the request locale', function () {
    config(['ispa-cms.available_languages' => ['vi', 'en']]);
    ($this->serve)([1 => null], [
        'name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero banner', 'fr' => 'ignored'],
        'description_translations' => ['vi' => 'Băng rôn lớn'],
    ]);
    Sanctum::actingAs($this->admin);

    $this->withHeader('X-Locale', 'vi')->getJson(WIDGET_HUB_BASE . '/hub-catalog?refresh=1')->assertOk()
        ->assertJsonPath('data.0.name', 'Hero')
        ->assertJsonPath('data.0.display_name', 'Ảnh bìa')
        ->assertJsonPath('data.0.display_description', 'Băng rôn lớn')
        ->assertJsonPath('data.0.name_translations', ['en' => 'Hero banner', 'vi' => 'Ảnh bìa']);
    $this->withHeader('X-Locale', 'en')->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk()
        ->assertJsonPath('data.0.display_name', 'Hero banner')
        ->assertJsonPath('data.0.display_description', 'Big banner');

    // A hub that sends no translations (before the hub migration): the plain name.
    ($this->serve)([1 => null]);
    $this->withHeader('X-Locale', 'vi')->getJson(WIDGET_HUB_BASE . '/hub-catalog?refresh=1')->assertOk()
        ->assertJsonPath('data.0.display_name', 'Hero')
        ->assertJsonPath('data.0.name_translations', null);
});

it('catalog entries carry what-changed notes above the installed version in the admin language', function () {
    $notes = [
        ['version' => 1, 'items' => [['type' => 'added', 'vi' => 'Bản đầu tiên', 'en' => 'First release']]],
        ['version' => 3, 'items' => [
            ['type' => 'fixed', 'vi' => 'Sửa ảnh bị lệch', 'en' => 'Fixed shifted images'],
            ['type' => 'bogus', 'vi' => 'bỏ qua', 'en' => 'dropped'],
        ]],
        ['version' => 2, 'items' => [['type' => 'changed', 'vi' => 'Đổi màu nút', 'en' => 'New button colour']]],
        ['version' => 'x', 'items' => []],
    ];
    ($this->serve)([1 => widgetHubSigned($this->secretKey, 1, ['default' => '<p>v1</p>']), 2 => null, 3 => null], ['changes' => $notes]);
    Sanctum::actingAs($this->admin);

    // Not installed: every version, newest first, malformed items and versions dropped.
    $this->withHeader('X-Admin-Locale', 'en')->getJson(WIDGET_HUB_BASE . '/hub-catalog?refresh=1')->assertOk()
        ->assertJsonPath('data.0.changes', [
            ['version' => 3, 'items' => [['type' => 'fixed', 'text' => 'Fixed shifted images']]],
            ['version' => 2, 'items' => [['type' => 'changed', 'text' => 'New button colour']]],
            ['version' => 1, 'items' => [['type' => 'added', 'text' => 'First release']]],
        ]);

    // Installed at 1: only the newer versions; a language without its own text falls back to Vietnamese.
    $widget = new Widget();
    $widget->forceFill(['widget_key' => 'example.hero', 'name' => 'Hero', 'origin' => 'hub', 'publisher' => 'iSPA', 'version' => 1])->save();
    $this->withHeader('X-Admin-Locale', 'vi')->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk()
        ->assertJsonPath('data.0.installed_version', 1)
        ->assertJsonPath('data.0.changes', [
            ['version' => 3, 'items' => [['type' => 'fixed', 'text' => 'Sửa ảnh bị lệch']]],
            ['version' => 2, 'items' => [['type' => 'changed', 'text' => 'Đổi màu nút']]],
        ]);
    expect(\Cms\Core\Support\Widget\WidgetChangeNotes::localize([['type' => 'added', 'vi' => 'Thêm', 'en' => 'Added']], 'fr'))
        ->toBe([['type' => 'added', 'text' => 'Thêm']]);

    // Up to date: nothing new.
    $widget->forceFill(['version' => 3])->save();
    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk()->assertJsonPath('data.0.changes', []);

    // An older hub without the field, and a catalog cached before notes existed: no notes.
    ($this->serve)([1 => null, 2 => null, 3 => null]);
    $widget->forceFill(['version' => 1])->save();
    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog?refresh=1')->assertOk()->assertJsonPath('data.0.changes', []);
    $key = WidgetHubCatalog::CACHE_KEY . ':' . sha1((string) config('app.url', ''));
    $cached = Cache::get($key);
    unset($cached[0]['changes']);
    Cache::put($key, $cached, 600);
    $this->getJson(WIDGET_HUB_BASE . '/hub-catalog')->assertOk()->assertJsonPath('data.0.changes', []);
});
