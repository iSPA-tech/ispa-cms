<?php

namespace Tests\Unit\Support;

use App\Support\LocaleManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocaleManagerTest extends TestCase
{
    public function test_sanitize_prefers_base_locale_when_available(): void
    {
        config()->set('ispa-cms.available_languages', ['en', 'en-US', 'vi', 'vi-VN']);

        $this->assertSame('vi', LocaleManager::sanitize('vi-VN'));
        $this->assertSame('en', LocaleManager::sanitize('en-US'));
    }

    public function test_sanitize_returns_regional_when_base_not_available(): void
    {
        config()->set('ispa-cms.available_languages', ['vi-VN']);

        $this->assertSame('vi-VN', LocaleManager::sanitize('vi-VN'));
        $this->assertSame('vi-VN', LocaleManager::sanitize('vi'));
    }

    public function test_sanitize_normalizes_underscored_locale(): void
    {
        config()->set('ispa-cms.available_languages', ['en']);

        $this->assertSame('en', LocaleManager::sanitize('en_US'));
    }

    public function test_project_default_locale_overrides_database_default_for_api(): void
    {
        config()->set('ispa-cms.available_languages', ['en', 'vi']);
        config()->set('ispa-cms.project_default_locale', 'en');

        $this->assertSame('en', LocaleManager::determineForApi(Request::create('/api/test')));
    }

    /**
     * A stale object cached by an older release must be treated as a cache miss, not a TypeError.
     */
    public function test_stale_object_in_default_language_cache_is_recomputed_from_database(): void
    {
        $this->createLanguagesTable();
        config()->set('ispa-cms.available_languages', ['vi']);
        config()->set('ispa-cms.project_default_locale', '');
        Cache::put('default_language', unserialize('O:15:"App\\Models\\Gone":0:{}'), 86400);

        $locale = LocaleManager::determineForWeb(Request::create('/'));

        $this->assertSame('vi', $locale);
        $this->assertSame('vi', Cache::get('default_language'));
    }

    /**
     * A valid cached string is returned as-is without touching the database.
     */
    public function test_valid_cached_default_language_skips_database(): void
    {
        config()->set('ispa-cms.available_languages', ['en', 'vi']);
        config()->set('ispa-cms.project_default_locale', '');
        Cache::put('default_language', 'en', 86400);

        DB::enableQueryLog();
        $locale = LocaleManager::determineForWeb(Request::create('/'));
        $languageQueries = array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'languages'));

        $this->assertSame('en', $locale);
        $this->assertCount(0, $languageQueries);
    }

    private function createLanguagesTable(): void
    {
        Schema::dropIfExists('languages');
        Schema::create('languages', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('path');
            $table->boolean('default_web')->default(false);
            $table->boolean('for_web')->default(true);
            $table->softDeletes();
        });
        DB::table('languages')->insert(['name' => 'Vietnamese', 'path' => 'vi', 'default_web' => true, 'for_web' => true]);
    }
}
