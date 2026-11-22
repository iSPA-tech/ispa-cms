<?php

declare(strict_types=1);

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetBundleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Fresh Ed25519 test keypair: [secret, publicB64, keyId].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function widgetHubKeypair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);

    return [sodium_crypto_sign_secretkey($pair), base64_encode($public), KeyId::fromPublicKey($public)];
}

/**
 * A small bundle with one design, one input and the given assets.
 *
 * @param list<array{path: string, sha256: string, base64: string}> $assets
 */
function widgetHubBundle(int $version = 3, array $assets = [], ?string $preview = null): WidgetBundle
{
    return WidgetBundle::create(
        widgetKey: 'example.hero',
        version: $version,
        publisher: 'iSPA',
        widget: ['name' => 'Hero', 'description' => 'Big banner', 'js' => null],
        designs: [[
            'theme_key' => 'default',
            'name' => 'Mặc định',
            'content' => '<section>{title}{query:post.latest variable="$post" limit="3" template=`{{ $post->name }}`}</section>',
            'css' => '.hero{color:red}',
            'query' => [],
            'project_name' => ['Default'],
            'preview_png_base64' => $preview,
        ]],
        inputs: [[
            'input_key' => 'title', 'name' => 'title', 'type' => 'text', 'default_value' => 'Xin chào', 'options' => null,
        ]],
        assets: $assets,
        requires: ['renderer' => WidgetBundle::WIDGET_RENDERER_VERSION, 'resolvers' => ['query:post.latest' => 1]],
    );
}

function widgetHubVerifier(array $trusted, array $revoked = []): WidgetBundleVerifier
{
    return new WidgetBundleVerifier(new PackageVerifier($trusted, $revoked));
}

it('canonical json round trips and hash ignores version', function () {
    $bundle = widgetHubBundle(3, [], null);
    $json = $bundle->toCanonicalJson();

    $again = WidgetBundle::fromCanonicalJson($json);
    expect($again->toCanonicalJson())->toBe($json)
        ->and($again->contentSha256)->toBe($bundle->contentSha256)
        ->and($again->designs[0]['content'])->toBe($bundle->designs[0]['content'])
        ->and($json)->toContain('Xin chào')
        ->and($json)->not->toContain('\\/');

    // Version and preview images do not move the content hash; content does.
    expect(widgetHubBundle(9, [], widgetHubPng())->contentSha256)->toBe($bundle->contentSha256);

    $edited = WidgetBundle::create(
        widgetKey: $bundle->widgetKey,
        version: 3,
        publisher: $bundle->publisher,
        widget: ['name' => 'Hero 2'] + $bundle->widget,
        designs: $bundle->designs,
        inputs: $bundle->inputs,
        assets: $bundle->assets,
        requires: $bundle->requires,
    );
    expect($edited->contentSha256)->not->toBe($bundle->contentSha256);
});

it('verifies a bundle signed with a trusted key', function () {
    [$secret, $public, $keyId] = widgetHubKeypair();
    $sig = (new PackageSigner())->envelope(widgetHubBundle()->toCanonicalJson(), $secret);

    $bundle = widgetHubVerifier([$keyId => $public])->verify($sig);

    expect($bundle->widgetKey)->toBe('example.hero')
        ->and($bundle->version)->toBe(3)
        ->and($bundle->requires['resolvers'])->toBe(['query:post.latest' => 1]);
});

it('tampered bundle is refused and writes nothing', function () {
    [$secret, $public, $keyId] = widgetHubKeypair();
    $doc = json_decode((new PackageSigner())->envelope(widgetHubBundle()->toCanonicalJson(), $secret), true);

    $bytes = base64_decode($doc['manifest']);
    $at = strpos($bytes, '<section>') + 1;
    $bytes[$at] = $bytes[$at] === 's' ? 'S' : 's';
    $doc['manifest'] = base64_encode($bytes);

    $before = [Widget::count(), WidgetTheme::count(), WidgetInput::count()];

    expect(fn () => widgetHubVerifier([$keyId => $public])->verify(json_encode($doc)))
        ->toThrow(WidgetBundleUntrusted::class);
    expect([Widget::count(), WidgetTheme::count(), WidgetInput::count()])->toBe($before);
});

it('revoked or unknown key refused', function () {
    [$secret, $public, $keyId] = widgetHubKeypair();
    [, $otherPublic, $otherKeyId] = widgetHubKeypair();
    $sig = (new PackageSigner())->envelope(widgetHubBundle()->toCanonicalJson(), $secret);

    expect(fn () => widgetHubVerifier([$otherKeyId => $otherPublic])->verify($sig))
        ->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifier([$keyId => $public], [$keyId])->verify($sig))
        ->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifier([$keyId => $public])->verify('not json'))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('asset sha mismatch refused', function () {
    [$secret, $public, $keyId] = widgetHubKeypair();
    $good = ['path' => 'images/hero.png', 'sha256' => hash('sha256', 'png-bytes'), 'base64' => base64_encode('png-bytes')];
    $bad = ['path' => 'images/hero.png', 'sha256' => hash('sha256', 'other'), 'base64' => base64_encode('png-bytes')];
    $signer = new PackageSigner();

    $ok = widgetHubVerifier([$keyId => $public])->verify($signer->envelope(widgetHubBundle(3, [$good])->toCanonicalJson(), $secret));
    expect($ok->assets)->toEqual([$good]);

    expect(fn () => widgetHubVerifier([$keyId => $public])->verify($signer->envelope(widgetHubBundle(3, [$bad])->toCanonicalJson(), $secret)))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('refuses signed bytes that are not the canonical form', function () {
    [$secret, $public, $keyId] = widgetHubKeypair();
    $pretty = json_encode(json_decode(widgetHubBundle()->toCanonicalJson(), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect(fn () => widgetHubVerifier([$keyId => $public])->verify((new PackageSigner())->envelope($pretty, $secret)))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('builds a bundle from the local database', function () {
    $widget = Widget::forceCreate(['widget_key' => 'example.hero', 'name' => 'Hero', 'description' => 'd', 'js' => 'console.log(1)']);
    WidgetTheme::forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'zeta', 'name' => 'Z', 'content' => '<p>{query:product.hot variable="$p" limit="2" template=`x`}</p>',
        'css' => null, 'query' => ['banner.main_slide'], 'project_name' => ['Default'],
    ]);
    WidgetTheme::forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'alpha', 'name' => 'A', 'content' => '<p>{title}</p>',
        'css' => '.a{}', 'query' => [], 'project_name' => ['Default', 'ExampleShop'],
    ]);
    WidgetInput::forceCreate(['widget_id' => $widget->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text', 'default_value' => 'Hi', 'options' => null]);

    $bundle = (new WidgetBundleBuilder())->build('example.hero', 4, 'iSPA', ['alpha' => widgetHubPng()]);

    expect($bundle->version)->toBe(4)
        ->and($bundle->widget)->toBe(['name' => 'Hero', 'description' => 'd', 'js' => 'console.log(1)'])
        ->and(array_column($bundle->designs, 'theme_key'))->toBe(['alpha', 'zeta'])
        ->and($bundle->designs[0]['preview_png_base64'])->toBe(widgetHubPng())
        ->and($bundle->designs[1]['preview_png_base64'])->toBeNull()
        ->and($bundle->designs[0]['project_name'])->toBe(['Default', 'ExampleShop'])
        ->and($bundle->inputs)->toBe([['default_value' => 'Hi', 'input_key' => 'title', 'name' => 'title', 'options' => null, 'type' => 'text']])
        ->and($bundle->assets)->toBe([])
        ->and($bundle->requires)->toBe([
            'renderer' => WidgetBundle::WIDGET_RENDERER_VERSION,
            'resolvers' => ['query:banner.main_slide' => 1, 'query:product.hot' => 1],
        ])
        ->and(WidgetBundle::fromCanonicalJson($bundle->toCanonicalJson())->toCanonicalJson())->toBe($bundle->toCanonicalJson());
});

/**
 * Base64 of bytes that start with the PNG signature.
 */
function widgetHubPng(string $tail = 'x'): string
{
    return base64_encode("\x89PNG\r\n\x1a\n" . $tail);
}

/**
 * Sign a hand-written bundle document (keys in any order; the canonical encoder sorts them) and verify it.
 *
 * @param callable(array): array $mutate
 */
function widgetHubVerifyMutated(callable $mutate): WidgetBundle
{
    [$secret, $public, $keyId] = widgetHubKeypair();
    $original = json_decode(widgetHubBundle()->toCanonicalJson(), true);
    $doc = $mutate($original);
    // Keep the content hash consistent with the mutation unless the mutation is about the hash itself.
    if ($doc['content_sha256'] === $original['content_sha256']) {
        $content = $doc;
        unset($content['version'], $content['content_sha256']);
        foreach ($content['designs'] as $i => $design) {
            unset($content['designs'][$i]['preview_png_base64']);
        }
        $doc['content_sha256'] = hash('sha256', json_encode(widgetHubSortKeys($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    $bytes = json_encode(widgetHubSortKeys($doc), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return widgetHubVerifier([$keyId => $public])->verify((new PackageSigner())->envelope($bytes, $secret));
}

function widgetHubSortKeys(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map('widgetHubSortKeys', $value);
}

function widgetHubAsset(string $path, string $bytes = 'asset-bytes'): array
{
    return ['base64' => base64_encode($bytes), 'path' => $path, 'sha256' => hash('sha256', $bytes)];
}

it('accepts a safe asset path', function () {
    $bundle = widgetHubVerifyMutated(fn (array $d) => ['assets' => [widgetHubAsset('fonts/a-b.woff2'), widgetHubAsset('images/hero_1.v2.png')]] + $d);

    expect(array_column($bundle->assets, 'path'))->toBe(['fonts/a-b.woff2', 'images/hero_1.v2.png']);
});

it('refuses unsafe asset paths as untrusted', function (string $path) {
    expect(fn () => widgetHubVerifyMutated(fn (array $d) => ['assets' => [widgetHubAsset($path)]] + $d))
        ->toThrow(WidgetBundleUntrusted::class);
})->with([
    'null byte' => "a\0.png",
    'backslash' => 'images\\x.png',
    'drive letter' => 'C:/x.png',
    'dot segment' => './x.png',
    'dot dot' => '../x.png',
    'empty segment' => 'images//x.png',
    'leading slash' => '/x.png',
    'trailing slash' => 'images/',
    'htaccess' => '.htaccess',
    'hidden dir' => '.git/x.png',
    'php' => 'x.php',
    'blade' => 'x.blade.php',
    'no extension' => 'images/x',
    'phar' => 'x.png.phar',
    'empty' => '',
    'control char' => "x\n.png",
]);

it('refuses a stored sha256 that is not lowercase hex', function () {
    $upper = widgetHubAsset('x.png');
    $upper['sha256'] = strtoupper($upper['sha256']);

    expect(fn () => widgetHubVerifyMutated(fn (array $d) => ['assets' => [$upper]] + $d))->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifyMutated(fn (array $d) => ['content_sha256' => 'abc'] + $d))->toThrow(WidgetBundleUntrusted::class);
});

it('refuses a wrong content hash', function () {
    expect(fn () => widgetHubVerifyMutated(fn (array $d) => ['content_sha256' => hash('sha256', 'other')] + $d))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('refuses malformed identifiers', function (string $field, string $value) {
    $mutate = match ($field) {
        'widget_key', 'publisher' => fn (array $d) => [$field => $value] + $d,
        'theme_key' => function (array $d) use ($value) {
            $d['designs'][0]['theme_key'] = $value;

            return $d;
        },
        'input_key' => function (array $d) use ($value) {
            $d['inputs'][0]['input_key'] = $value;

            return $d;
        },
    };

    expect(fn () => widgetHubVerifyMutated($mutate))->toThrow(WidgetBundleUntrusted::class);
})->with([
    ['widget_key', 'a/b'],
    ['widget_key', "a\0b"],
    ['widget_key', '.hidden'],
    ['widget_key', str_repeat('a', 192)],
    ['theme_key', '../x'],
    ['theme_key', 'a b'],
    ['input_key', 'a<b'],
    ['publisher', 'iSPA/x'],
    ['publisher', ''],
]);

it('accepts identifiers used by real widgets', function (string $key) {
    $bundle = widgetHubVerifyMutated(function (array $d) use ($key) {
        $d['widget_key'] = $key;
        $d['designs'][0]['theme_key'] = $key;
        $d['inputs'][0]['input_key'] = $key;

        return $d;
    });

    expect($bundle->widgetKey)->toBe($key);
})->with([
    'exampleshop.hero-slider', 'site.exampleshop.hero-slider-2', 'legacy.widget-12', 'core.widget-1',
    'manual.6f1c2b9e-0d7a-4b3e-9a51-2c8f4e7d1a03', 'manual-6f1c2b9e-0d7a-4b3e-9a51-2c8f4e7d1a03', 'author2-name', 's1_url', 'BHaku',
]);

it('refuses duplicate or unsorted designs, inputs and assets', function () {
    $twoDesigns = function (array $d, array $keys) {
        $d['designs'] = array_map(fn ($k) => ['theme_key' => $k] + $d['designs'][0], $keys);

        return $d;
    };
    $twoInputs = function (array $d, array $keys) {
        $d['inputs'] = array_map(fn ($k) => ['input_key' => $k] + $d['inputs'][0], $keys);

        return $d;
    };

    expect(widgetHubVerifyMutated(fn ($d) => $twoDesigns($d, ['a', 'b']))->designs)->toHaveCount(2);
    expect(fn () => widgetHubVerifyMutated(fn ($d) => $twoDesigns($d, ['a', 'a'])))->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifyMutated(fn ($d) => $twoDesigns($d, ['b', 'a'])))->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifyMutated(fn ($d) => $twoInputs($d, ['t', 't'])))->toThrow(WidgetBundleUntrusted::class);
    expect(fn () => widgetHubVerifyMutated(fn ($d) => ['assets' => [widgetHubAsset('a.png'), widgetHubAsset('a.png')]] + $d))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('refuses a preview that is not a small png', function (string $preview) {
    expect(fn () => widgetHubVerifyMutated(function (array $d) use ($preview) {
        $d['designs'][0]['preview_png_base64'] = $preview;

        return $d;
    }))->toThrow(WidgetBundleUntrusted::class);
})->with([
    'not base64' => '***',
    'not png' => base64_encode('GIF89a....'),
    'too large' => base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 2 * 1024 * 1024)),
]);

it('accepts a png preview', function () {
    $bundle = widgetHubVerifyMutated(function (array $d) {
        $d['designs'][0]['preview_png_base64'] = widgetHubPng();

        return $d;
    });

    expect($bundle->designs[0]['preview_png_base64'])->toBe(widgetHubPng());
});

it('the default verifier trusts only widget release key ids, never a hub countersign key', function () {
    [$releaseSecret, $releasePublic, $releaseId] = widgetHubKeypair();
    [$hubSecret, $hubPublic, $hubId] = widgetHubKeypair();
    [$otherSecret, $otherPublic, $otherId] = widgetHubKeypair();
    config([
        // The countersign key is trusted for module packages and even listed among the widget ids by mistake.
        'core.module_packages.trusted_keys' => [$releaseId => $releasePublic, $hubId => $hubPublic, $otherId => $otherPublic],
        'core.module_packages.countersign_key_ids' => [$hubId],
        'core.module_packages.widget_bundle_key_ids' => [$releaseId, $hubId],
        'core.module_packages.revoked_key_ids' => [],
    ]);
    $json = widgetHubBundle()->toCanonicalJson();
    $signer = new PackageSigner();

    expect((new WidgetBundleVerifier())->verify($signer->envelope($json, $releaseSecret))->widgetKey)->toBe('example.hero');
    expect(fn () => (new WidgetBundleVerifier())->verify($signer->envelope($json, $hubSecret)))
        ->toThrow(WidgetBundleUntrusted::class);
    // Trusted for module packages but not a widget release key.
    expect(fn () => (new WidgetBundleVerifier())->verify($signer->envelope($json, $otherSecret)))
        ->toThrow(WidgetBundleUntrusted::class);

    config(['core.module_packages.revoked_key_ids' => [$releaseId]]);
    expect(fn () => (new WidgetBundleVerifier())->verify($signer->envelope($json, $releaseSecret)))
        ->toThrow(WidgetBundleUntrusted::class);
});

it('an injected verifier still refuses a hub countersign key', function () {
    [$hubSecret, $hubPublic, $hubId] = widgetHubKeypair();
    config(['core.module_packages.countersign_key_ids' => [$hubId]]);
    $sig = (new PackageSigner())->envelope(widgetHubBundle()->toCanonicalJson(), $hubSecret);

    expect(fn () => widgetHubVerifier([$hubId => $hubPublic])->verify($sig))->toThrow(WidgetBundleUntrusted::class);
});

/**
 * widgetHubBundle() with per-language names on the widget and its design.
 */
function widgetHubBundleWithNames(): WidgetBundle
{
    $bundle = widgetHubBundle();

    return WidgetBundle::create(
        widgetKey: $bundle->widgetKey,
        version: $bundle->version,
        publisher: $bundle->publisher,
        widget: $bundle->widget + [
            'name_translations' => ['vi' => ' Ảnh bìa ', 'en' => 'Hero', 'fr' => 'ignored'],
            'description_translations' => ['vi' => 'Băng rôn lớn'],
        ],
        designs: [['name_translations' => ['en' => 'Default', 'vi' => 'Mặc định']] + $bundle->designs[0]],
        inputs: $bundle->inputs,
        assets: $bundle->assets,
        requires: $bundle->requires,
    );
}

it('a bundle without per-language names keeps the bytes and hash of the format before them', function () {
    $bundle = widgetHubBundle();
    $withNulls = WidgetBundle::create(
        widgetKey: $bundle->widgetKey,
        version: $bundle->version,
        publisher: $bundle->publisher,
        widget: $bundle->widget + ['name_translations' => null, 'description_translations' => []],
        designs: [['name_translations' => ['vi' => '  ']] + $bundle->designs[0]],
        inputs: $bundle->inputs,
        assets: $bundle->assets,
        requires: $bundle->requires,
    );

    // The hash the format computed before translations existed: the sorted document without version and previews.
    $doc = json_decode($bundle->toCanonicalJson(), true);
    unset($doc['version'], $doc['content_sha256'], $doc['designs'][0]['preview_png_base64']);
    $legacyHash = hash('sha256', json_encode(widgetHubSortKeys($doc), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    expect($bundle->toCanonicalJson())->not->toContain('translations')
        ->and($withNulls->toCanonicalJson())->toBe($bundle->toCanonicalJson())
        ->and($bundle->contentSha256)->toBe($legacyHash);

    // A signed bundle in the old format still verifies.
    [$secret, $public, $keyId] = widgetHubKeypair();
    $verified = widgetHubVerifier([$keyId => $public])->verify((new PackageSigner())->envelope($bundle->toCanonicalJson(), $secret));
    expect($verified->widget)->not->toHaveKey('name_translations');
});

it('per-language names round trip in normal form and count as content', function () {
    $bundle = widgetHubBundleWithNames();

    expect($bundle->widget['name_translations'])->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($bundle->widget['description_translations'])->toBe(['vi' => 'Băng rôn lớn'])
        ->and($bundle->designs[0]['name_translations'])->toBe(['en' => 'Default', 'vi' => 'Mặc định'])
        ->and($bundle->contentSha256)->not->toBe(widgetHubBundle()->contentSha256);

    [$secret, $public, $keyId] = widgetHubKeypair();
    $verified = widgetHubVerifier([$keyId => $public])->verify((new PackageSigner())->envelope($bundle->toCanonicalJson(), $secret));
    expect($verified->toCanonicalJson())->toBe($bundle->toCanonicalJson())
        ->and($verified->designs[0]['name_translations'])->toBe(['en' => 'Default', 'vi' => 'Mặc định']);

    expect(WidgetBundle::withoutTranslations($bundle->widget))->toBe(widgetHubBundle()->widget);
});

it('refuses malformed per-language names as untrusted', function (string $where, mixed $value) {
    expect(fn () => widgetHubVerifyMutated(function (array $doc) use ($where, $value): array {
        if ($where === 'widget') {
            $doc['widget']['name_translations'] = $value;
        } else {
            $doc['designs'][0]['name_translations'] = $value;
        }

        return $doc;
    }))->toThrow(WidgetBundleUntrusted::class);
})->with([
    'unknown language' => ['widget', ['fr' => 'Bonjour']],
    'empty map' => ['widget', []],
    'not a string' => ['design', ['vi' => 5]],
    'blank' => ['design', ['vi' => '']],
    'untrimmed' => ['widget', ['vi' => ' Tên ']],
    'too long' => ['design', ['en' => str_repeat('x', 256)]],
    'not an object' => ['widget', 'Hero'],
]);

it('refuses an unknown extra widget or design key next to the translations', function () {
    expect(fn () => widgetHubVerifyMutated(function (array $doc): array {
        $doc['widget']['title_translations'] = ['vi' => 'x'];

        return $doc;
    }))->toThrow(WidgetBundleUntrusted::class);
});

it('builds per-language names from the local database', function () {
    $widget = Widget::forceCreate([
        'widget_key' => 'example.hero', 'name' => 'Hero', 'description' => 'd', 'js' => null,
        'name_translations' => ['vi' => 'Ảnh bìa', 'en' => 'Hero'],
    ]);
    WidgetTheme::forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'alpha', 'name' => 'A', 'content' => '<p>{title}</p>',
        'css' => null, 'query' => [], 'project_name' => ['Default'], 'name_translations' => ['vi' => 'Mặc định'],
    ]);
    WidgetTheme::forceCreate([
        'widget_id' => $widget->id, 'theme_key' => 'beta', 'name' => 'B', 'content' => '<p>{title}</p>',
        'css' => null, 'query' => [], 'project_name' => ['Default'],
    ]);

    $bundle = (new WidgetBundleBuilder())->build('example.hero', 2, 'iSPA');

    expect($bundle->widget)->toBe(['name' => 'Hero', 'description' => 'd', 'js' => null, 'name_translations' => ['en' => 'Hero', 'vi' => 'Ảnh bìa']])
        ->and($bundle->designs[0]['name_translations'])->toBe(['vi' => 'Mặc định'])
        ->and($bundle->designs[1])->not->toHaveKey('name_translations');
});
