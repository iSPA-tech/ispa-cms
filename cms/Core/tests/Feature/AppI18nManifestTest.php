<?php

namespace Cms\Core\Tests\Feature;

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Tests\TestCase;

final class AppI18nManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            HttpsProtocol::class,
            Localization::class,
            ApiLocalization::class,
        ]);
    }

    public function test_manifest_returns_i18n_version_and_platform_info(): void
    {
        config()->set('ispa-cms.app_version', '2026.03.0');
        config()->set('ispa-cms.mobile_app', [
            'android' => [
                'latest' => '1.2.0',
                'minimum' => null,
                'store_url' => 'https://play.google.com/store/apps/details?id=x',
            ],
            'ios' => [
                'latest' => null,
                'minimum' => null,
                'store_url' => null,
            ],
        ]);

        $this->getJson('/api/i18n/app/manifest.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('i18n.version', '2026.03.0')
            ->assertJsonPath('app.android.latest', '1.2.0')
            ->assertJsonPath(
                'app.android.store_url',
                'https://play.google.com/store/apps/details?id=x',
            )
            ->assertJsonMissingPath('app.ios');
    }

    public function test_manifest_serves_empty_app_block_without_env(): void
    {
        config()->set('ispa-cms.app_version', '2026.03.0');
        config()->set('ispa-cms.mobile_app', [
            'android' => ['latest' => null, 'minimum' => null, 'store_url' => null],
            'ios' => ['latest' => null, 'minimum' => null, 'store_url' => null],
        ]);

        $response = $this->getJson('/api/i18n/app/manifest.json')->assertOk();

        $this->assertSame('2026.03.0', $response->json('i18n.version'));
        $this->assertSame([], (array) $response->json('app'));
    }
}
