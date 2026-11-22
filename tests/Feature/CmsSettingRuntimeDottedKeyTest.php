<?php

namespace Tests\Feature;

use Cms\Core\Services\CMSSettingRuntime;
use Tests\TestCase;

/**
 * The warmed scope maps are FLAT and keyed by the dotted setting_key, so a
 * dotted lookup has to match the exact key. data_get() alone splits on dots
 * and walks a nested structure, so it matched nothing — every dotted setting
 * silently resolved to its default, which is how the captcha gateway kept
 * reading 'off' while Turnstile was fully configured in the admin.
 */
class CmsSettingRuntimeDottedKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The application config is already loaded by TestCase, so update the
        // runtime flag directly instead of mutating the now-stale env source.
        config(['ispa-cms.setting_runtime_enabled' => true]);
    }

    protected function tearDown(): void
    {
        config(['ispa-cms.setting_runtime_enabled' => false]);

        parent::tearDown();
    }

    private function runtimeWithMap(array $public): CMSSettingRuntime
    {
        $runtime = new CMSSettingRuntime();

        $cache = new \ReflectionProperty(CMSSettingRuntime::class, 'localCache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['env' => [], 'private' => [], 'public' => $public]);

        return $runtime;
    }

    public function test_flat_dotted_keys_resolve(): void
    {
        $runtime = $this->runtimeWithMap([
            'advanced.recaptcha_provider' => 'cloudflare',
        ]);

        $this->assertSame('cloudflare', $runtime->get('advanced.recaptcha_provider', 'off'));
    }

    public function test_missing_keys_still_fall_back_to_the_default(): void
    {
        $runtime = $this->runtimeWithMap(['advanced.other' => 'x']);

        $this->assertSame('off', $runtime->get('advanced.recaptcha_provider', 'off'));
    }

    public function test_genuinely_nested_paths_keep_working(): void
    {
        $runtime = $this->runtimeWithMap([
            'core' => ['ai_runtime' => ['default_provider' => 'openai']],
        ]);

        $this->assertSame('openai', $runtime->get('core.ai_runtime.default_provider'));
    }
}
