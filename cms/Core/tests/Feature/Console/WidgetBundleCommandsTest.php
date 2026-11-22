<?php

declare(strict_types=1);

use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogStatusService;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Services\Widget\Hub\WidgetBundleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Throwaway one-widget catalog (publisher TestPub) in a temp directory, seeded into the database.
 *
 * @param array<string, mixed> $extra extra keys on the exported widget row
 */
function bundleCatalog(object $test, array $extra = []): void
{
    $widgets = [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'description' => 'Big banner', 'js' => null, 'version' => 1] + $extra];
    $themes = [[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default',
        'content' => '<p>{title}</p>', 'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]];
    $inputs = [[
        'id' => 10, 'widget_id' => 1, 'input_key' => 'title', 'name' => 'title',
        'type' => 'text', 'default_value' => 'Xin chào', 'options' => null,
    ]];
    File::ensureDirectoryExists($test->catalog);
    File::put($test->catalog . '/widgets.php', '<?php return ' . var_export($widgets, true) . ';');
    File::put($test->catalog . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');
    File::put($test->catalog . '/widget_inputs.php', '<?php return ' . var_export($inputs, true) . ';');
    $test->seed(WidgetSeeder::class);
}

/** Fresh Ed25519 key file (mode 0600, temp dir): [path, publicB64, keyId, secretB64]. */
function bundleKeyFile(string $dir): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secretB64 = base64_encode(sodium_crypto_sign_secretkey($pair));
    $path = $dir . '/release-signing.key';
    File::put($path, $secretB64);
    chmod($path, 0600);

    return [$path, base64_encode($public), KeyId::fromPublicKey($public), $secretB64];
}

function runBundleCommand(string $command, array $args): array
{
    $exit = Artisan::call($command, $args);

    return [$exit, Artisan::output()];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 22)->setTime(10, 0));
    $this->root = sys_get_temp_dir() . '/ispa-widget-bundle-' . uniqid();
    $this->catalog = $this->root . '/catalog';
    $this->out = $this->root . '/out';
    File::ensureDirectoryExists($this->out);
    config()->set('ispa-cms.folder_name', 'Default');
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->catalog]);
    config()->set('ispa-cms.widget_hub.publish_ledger', $this->root . '/ledger/published.json');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('build refuses when authoring is off', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', false);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true,
    ]);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('authoring')
        ->and(File::files($this->out))->toBe([]);
});

it('build writes a canonical bundle with the next version', function () {
    bundleCatalog($this, ['hub_version' => 4, 'hub_content_sha256' => str_repeat('a', 64)]);
    config()->set('ispa-cms.widget.catalog_authoring', true);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true,
    ]);

    $path = $this->out . '/testpub.hero-20261101.bundle.json';
    expect($exit)->toBe(0, $output)->and(is_file($path))->toBeTrue();

    $bytes = (string) file_get_contents($path);
    $bundle = WidgetBundle::fromCanonicalJson($bytes);
    expect($bundle->toCanonicalJson())->toBe($bytes)
        ->and($bundle->version)->toBe(20261101)
        ->and($bundle->publisher)->toBe('TestPub')
        ->and($bundle->designs[0]['theme_key'])->toBe('default')
        ->and($bundle->designs[0]['preview_png_base64'])->toBeNull()
        ->and($bundle->inputs[0]['input_key'])->toBe('title')
        ->and($bundle->contentSha256)->toBe($bundle->computeContentSha256());

    // An explicit version wins; a version below 1 is refused.
    [$exit] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--hub-version' => '9', '--out' => $this->out, '--no-preview' => true,
    ]);
    expect($exit)->toBe(0)->and(is_file($this->out . '/testpub.hero-9.bundle.json'))->toBeTrue();
    [$exit] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--hub-version' => '0', '--out' => $this->out, '--no-preview' => true,
    ]);
    expect($exit)->not->toBe(0);
    // An explicit version must exceed the exported one.
    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--hub-version' => '4', '--out' => $this->out, '--no-preview' => true,
    ]);
    expect($exit)->not->toBe(0)->and($output)->toContain('greater than');
});

it('build counts up within one month and refuses a hundredth build', function () {
    config()->set('ispa-cms.widget.catalog_authoring', true);
    bundleCatalog($this, ['hub_version' => 20261101, 'hub_content_sha256' => str_repeat('a', 64)]);
    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->toBe(0, $output)->and(is_file($this->out . '/testpub.hero-20261102.bundle.json'))->toBeTrue();

    bundleCatalog($this, ['hub_version' => 20261199, 'hub_content_sha256' => str_repeat('a', 64)]);
    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->not->toBe(0)->and($output)->toContain('99');

    // A small legacy version sorts below this month's CalVer.
    bundleCatalog($this, ['hub_version' => 3, 'hub_content_sha256' => str_repeat('a', 64)]);
    [$exit] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->toBe(0)->and(is_file($this->out . '/testpub.hero-20261101.bundle.json'))->toBeTrue();
});

it('build starts at today\'s CalVer for a widget never published', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', [
        'widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true,
    ]);

    expect($exit)->toBe(0, $output)->and(is_file($this->out . '/testpub.hero-' . now()->format('Ym') . '01.bundle.json'))->toBeTrue();
});

it('sign writes an envelope the verifier accepts', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    $bundlePath = $this->out . '/testpub.hero-20261101.bundle.json';
    [$keyPath, $publicB64, $keyId, $secretB64] = bundleKeyFile($this->root);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-sign', ['bundle' => $bundlePath, '--key-file' => $keyPath]);

    $sigPath = $bundlePath . '.sig.json';
    expect($exit)->toBe(0, $output)
        ->and(is_file($sigPath))->toBeTrue()
        ->and($output)->toContain($keyId)
        ->and($output)->not->toContain($secretB64);

    $verified = (new WidgetBundleVerifier(new PackageVerifier([$keyId => $publicB64], [])))->verify((string) file_get_contents($sigPath));
    expect($verified->widgetKey)->toBe('testpub.hero')->and($verified->version)->toBe(20261101);

    // The signed version is recorded as pending (not yet accepted by the hub); signing twice never overwrites.
    $ledger = json_decode((string) file_get_contents($this->root . '/ledger/published.json'), true);
    expect($ledger['testpub.hero'])->toBe(['pending' => ['hub_content_sha256' => $verified->contentSha256, 'hub_version' => 20261101, 'publisher' => 'TestPub']]);
    [$again] = runBundleCommand('cms:widget-bundle-sign', ['bundle' => $bundlePath, '--key-file' => $keyPath]);
    expect($again)->not->toBe(0);
});

it('sign refuses a key file that is readable by others or a non-canonical bundle', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    $bundlePath = $this->out . '/testpub.hero-20261101.bundle.json';
    [$keyPath] = bundleKeyFile($this->root);

    chmod($keyPath, 0644);
    [$exit, $output] = runBundleCommand('cms:widget-bundle-sign', ['bundle' => $bundlePath, '--key-file' => $keyPath]);
    expect($exit)->not->toBe(0)->and($output)->toContain('0600');
    chmod($keyPath, 0600);

    $pretty = $this->out . '/pretty.bundle.json';
    File::put($pretty, json_encode(json_decode((string) file_get_contents($bundlePath), true), JSON_PRETTY_PRINT));
    [$exit] = runBundleCommand('cms:widget-bundle-sign', ['bundle' => $pretty, '--key-file' => $keyPath]);
    expect($exit)->not->toBe(0)->and(is_file($pretty . '.sig.json'))->toBeFalse();
});

it('a signed bundle is not recorded by export until the owner marks it published', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '3', '--out' => $this->out, '--no-preview' => true]);
    [$keyPath, $publicB64, $keyId] = bundleKeyFile($this->root);
    config()->set('core.module_packages.trusted_keys', [$keyId => $publicB64]);
    config()->set('core.module_packages.widget_bundle_key_ids', [$keyId]);
    config()->set('core.module_packages.revoked_key_ids', []);
    $sigPath = $this->out . '/testpub.hero-3.bundle.json.sig.json';
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-3.bundle.json', '--key-file' => $keyPath]);

    // Never published yet: the exported row has no published hash.
    expect(app(WidgetCatalogStatusService::class)->status()['unpublished_widget_keys'])->toBe(['testpub.hero']);

    // Signed only: export leaves the row alone and names the pending bundle.
    [$exit, $output] = runBundleCommand('cms:export-widgets', ['--target' => $this->root . '/pending', '--publisher' => ['TestPub']]);
    $pendingRows = require $this->root . '/pending/TestPub/widgets.php';
    expect($exit)->toBe(0, $output)
        ->and($pendingRows[0])->not->toHaveKey('hub_version')
        ->and($output)->toContain('chờ xác nhận publish')
        ->and($output)->toContain('testpub.hero');

    [$exit, $output] = runBundleCommand('cms:widget-bundle-mark-published', ['sig' => $sigPath]);
    expect($exit)->toBe(0, $output);
    // The authoring row now carries the published version, so the library offers no "Có bản mới" for it.
    expect((int) DB::table('widgets')->where('widget_key', 'testpub.hero')->value('version'))->toBe(3);

    [$exit, $output] = runBundleCommand('cms:export-widgets', ['--target' => $this->root . '/export', '--publisher' => ['TestPub']]);
    expect($exit)->toBe(0, $output);
    $rows = require $this->root . '/export/TestPub/widgets.php';
    $sha = app(WidgetBundleBuilder::class)->build('testpub.hero', 1, 'TestPub')->contentSha256;
    expect($rows[0]['hub_version'])->toBe(3)->and($rows[0]['hub_content_sha256'])->toBe($sha);

    // The catalog now carries the published hash: nothing to publish until the widget changes.
    File::copy($this->root . '/export/TestPub/widgets.php', $this->catalog . '/widgets.php');
    $status = app(WidgetCatalogStatusService::class)->status();
    expect($status['unpublished_widget_keys'])->toBe([])->and($status['unexported_widget_keys'])->toBe([]);

    DB::table('widget_themes')->where('theme_key', 'default')->update(['content' => '<h1>{title}</h1>']);
    expect(app(WidgetCatalogStatusService::class)->status()['unpublished_widget_keys'])->toBe(['testpub.hero']);

    // The next build defaults to this month's CalVer (above the exported hub version).
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    expect(is_file($this->out . '/testpub.hero-' . now()->format('Ym') . '01.bundle.json'))->toBeTrue();

    // Exporting again without a newer signature keeps the recorded version.
    File::delete($this->root . '/ledger/published.json');
    runBundleCommand('cms:export-widgets', ['--target' => $this->root . '/export2', '--publisher' => ['TestPub']]);
    $again = require $this->root . '/export2/TestPub/widgets.php';
    expect($again[0]['hub_version'])->toBe(3)->and($again[0]['hub_content_sha256'])->toBe($sha);
});

it('catalog verify accepts hub metadata and rejects a malformed one', function () {
    bundleCatalog($this, ['hub_version' => 2, 'hub_content_sha256' => str_repeat('b', 64)]);

    [$exit, $output] = runBundleCommand('cms:widget-catalog-verify', ['--files-only' => true]);
    expect($exit)->toBe(0, $output);

    bundleCatalog($this, ['hub_version' => 0, 'hub_content_sha256' => 'nope']);
    [$exit, $output] = runBundleCommand('cms:widget-catalog-verify', ['--files-only' => true]);
    expect($exit)->not->toBe(0)->and($output)->toContain('hub_version');
});

it('mark published refuses a signature that is not the pending bundle', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    [$keyPath, $publicB64, $keyId] = bundleKeyFile($this->root);
    config()->set('core.module_packages.trusted_keys', [$keyId => $publicB64]);
    config()->set('core.module_packages.widget_bundle_key_ids', [$keyId]);
    config()->set('core.module_packages.revoked_key_ids', []);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-2.bundle.json', '--key-file' => $keyPath]);
    // A later bundle is signed, so version 2 is no longer the pending one.
    DB::table('widget_themes')->where('theme_key', 'default')->update(['content' => '<h2>{title}</h2>']);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-20261101.bundle.json', '--key-file' => $keyPath]);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-mark-published', ['sig' => $this->out . '/testpub.hero-2.bundle.json.sig.json']);
    expect($exit)->not->toBe(0)->and($output)->toContain('pending');

    // A tampered signature file is refused before the ledger is read.
    $tampered = $this->out . '/tampered.sig.json';
    $envelope = json_decode((string) file_get_contents($this->out . '/testpub.hero-20261101.bundle.json.sig.json'), true);
    $envelope['manifest'] = base64_encode(str_replace('<h2>', '<h3>', base64_decode($envelope['manifest'])));
    File::put($tampered, json_encode($envelope));
    [$exit] = runBundleCommand('cms:widget-bundle-mark-published', ['sig' => $tampered]);
    expect($exit)->not->toBe(0);

    $ledger = json_decode((string) file_get_contents($this->root . '/ledger/published.json'), true);
    expect($ledger['testpub.hero'])->not->toHaveKey('published')->and($ledger['testpub.hero']['pending']['hub_version'])->toBe(20261101);

    config()->set('ispa-cms.widget.catalog_authoring', false);
    [$exit] = runBundleCommand('cms:widget-bundle-mark-published', ['sig' => $this->out . '/testpub.hero-20261101.bundle.json.sig.json']);
    expect($exit)->not->toBe(0);
});

it('rebuilding a bundle removes its stale signature', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    [$keyPath] = bundleKeyFile($this->root);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-2.bundle.json', '--key-file' => $keyPath]);
    expect(is_file($this->out . '/testpub.hero-2.bundle.json.sig.json'))->toBeTrue();

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->toBe(0, $output)
        ->and(is_file($this->out . '/testpub.hero-2.bundle.json.sig.json'))->toBeFalse()
        ->and($output)->toContain('signature');
});

it('build defaults past a pending signed version', function () {
    bundleCatalog($this, ['hub_version' => 4, 'hub_content_sha256' => str_repeat('a', 64)]);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    [$keyPath] = bundleKeyFile($this->root);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-20261101.bundle.json', '--key-file' => $keyPath]);

    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--out' => $this->out, '--no-preview' => true]);
    expect(is_file($this->out . '/testpub.hero-20261102.bundle.json'))->toBeTrue();
});

it('mark published leaves the local version alone when the widget changed after signing', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    [$keyPath, $publicB64, $keyId] = bundleKeyFile($this->root);
    config()->set('core.module_packages.trusted_keys', [$keyId => $publicB64]);
    config()->set('core.module_packages.widget_bundle_key_ids', [$keyId]);
    config()->set('core.module_packages.revoked_key_ids', []);
    runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '5', '--out' => $this->out, '--no-preview' => true]);
    runBundleCommand('cms:widget-bundle-sign', ['bundle' => $this->out . '/testpub.hero-5.bundle.json', '--key-file' => $keyPath]);
    $before = (int) DB::table('widgets')->where('widget_key', 'testpub.hero')->value('version');
    DB::table('widget_themes')->where('theme_key', 'default')->update(['content' => '<h3>{title}</h3>']);

    [$exit, $output] = runBundleCommand('cms:widget-bundle-mark-published', ['sig' => $this->out . '/testpub.hero-5.bundle.json.sig.json']);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('changed after signing')
        ->and((int) DB::table('widgets')->where('widget_key', 'testpub.hero')->value('version'))->toBe($before);
});

it('build writes the what-changed sidecar from the changelog and drops a stale one', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    $items = [['type' => 'added', 'vi' => ' Thêm kiểu mới ', 'en' => 'Added a design']];
    File::put($this->catalog . '/widget-changelogs.php', '<?php return ' . var_export(['testpub.hero' => [2 => $items]], true) . ';');

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    $sidecar = $this->out . '/testpub.hero-2.bundle.json.notes.json';
    expect($exit)->toBe(0, $output)
        ->and(json_decode((string) file_get_contents($sidecar), true))->toBe([
            'widget_key' => 'testpub.hero', 'version' => 2,
            'changes' => [['type' => 'added', 'vi' => 'Thêm kiểu mới', 'en' => 'Added a design']],
        ]);
    // The signed bundle never carries the notes.
    expect((string) file_get_contents($this->out . '/testpub.hero-2.bundle.json'))->not->toContain('Thêm kiểu mới');

    // Rebuilding the version once its notes are gone removes the old sidecar.
    File::put($this->catalog . '/widget-changelogs.php', '<?php return [];');
    [$exit] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->toBe(0)->and(is_file($sidecar))->toBeFalse();
});

it('build refuses an invalid changelog before writing anything', function () {
    bundleCatalog($this);
    config()->set('ispa-cms.widget.catalog_authoring', true);
    File::put($this->catalog . '/widget-changelogs.php', '<?php return ' . var_export(['testpub.hero' => [2 => [['type' => 'added', 'vi' => 'Chỉ tiếng Việt', 'en' => '']]]], true) . ';');

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '2', '--out' => $this->out, '--no-preview' => true]);
    expect($exit)->not->toBe(0)->and($output)->toContain('Changelog invalid')
        ->and(is_file($this->out . '/testpub.hero-2.bundle.json'))->toBeFalse();
});

it('notes-only writes just the sidecar of an already published version', function () {
    bundleCatalog($this, ['hub_version' => 5, 'hub_content_sha256' => str_repeat('a', 64)]);
    File::put($this->catalog . '/widget-changelogs.php', '<?php return ' . var_export(['testpub.hero' => [5 => [['type' => 'fixed', 'vi' => 'Sửa lỗi', 'en' => 'Fixed a bug']]]], true) . ';');

    // Works with authoring off and at the published version; no bundle is built.
    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '5', '--notes-only' => true, '--publisher' => 'TestPub', '--out' => $this->out]);
    expect($exit)->toBe(0, $output)
        ->and(is_file($this->out . '/testpub.hero-5.bundle.json.notes.json'))->toBeTrue()
        ->and(is_file($this->out . '/testpub.hero-5.bundle.json'))->toBeFalse();

    [$exit, $output] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--hub-version' => '6', '--notes-only' => true, '--publisher' => 'TestPub', '--out' => $this->out]);
    expect($exit)->not->toBe(0)->and($output)->toContain('No notes');
    [$exit] = runBundleCommand('cms:widget-bundle-build', ['widget_key' => 'testpub.hero', '--notes-only' => true, '--out' => $this->out]);
    expect($exit)->not->toBe(0);
});
