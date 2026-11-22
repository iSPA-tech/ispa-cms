<?php

namespace Tests\Feature;

use Cms\Core\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LocaleControllerRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ispa-cms.available_languages', ['en', 'vi']);
        config()->set('app.url', 'https://auto.ispa.io');
        URL::forceRootUrl('https://auto.ispa.io');
        URL::forceScheme('https');

        Cache::flush();

        Cache::put('language-locale-en', new Language([
            'name' => 'English',
            'currency' => '$',
            'path' => 'en',
            'path_alias' => 'en',
            'code' => 'en',
            'for_web' => true,
            'default_web' => false,
        ]));

        Cache::put('language-locale-vi', new Language([
            'name' => 'Tiếng Việt',
            'currency' => 'đ',
            'path' => 'vi',
            'path_alias' => 'vi',
            'code' => 'vi',
            'for_web' => true,
            'default_web' => true,
        ]));
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_lang_redirect_uses_previous_non_locale_url(): void
    {
        $this
            ->withHeader('referer', 'https://auto.ispa.io/about')
            ->get('https://auto.ispa.io/lang/en')
            ->assertRedirect('https://auto.ispa.io/about');
    }

    public function test_lang_redirect_without_referer_falls_back_for_enabled_locales(): void
    {
        foreach (['en', 'vi'] as $locale) {
            $this
                ->get("https://auto.ispa.io/lang/{$locale}")
                ->assertRedirect(route('home'));
        }
    }

    public function test_lang_redirect_falls_back_when_referer_is_malformed_locale_url(): void
    {
        $this
            ->withHeader('referer', 'https://auto.ispa.io/lang/en?%2Flang%2Fen=')
            ->get('https://auto.ispa.io/lang/en')
            ->assertRedirect(route('home'));
    }

    public function test_malformed_lang_url_without_referer_falls_back(): void
    {
        $this
            ->get('https://auto.ispa.io/lang/en?%2Flang%2Fen=')
            ->assertRedirect(route('home'));
    }

    public function test_lang_redirect_rejects_invalid_locale(): void
    {
        // Assert the 404 itself: rendering the themed error page needs menu tables and built assets that
        // this database-free test does not have.
        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->get('https://auto.ispa.io/lang/fr');
    }
}
