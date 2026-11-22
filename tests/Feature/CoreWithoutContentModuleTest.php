<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\ProvinceController;
use App\Http\Controllers\Website\AjaxController;
use App\Services\WidgetQueryService;
use Cms\Content\Interfaces\ContentInterface;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Nwidart\Modules\Facades\Module;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Content can be disabled. Since the Content table migrations live in cms/Content, a site
 * created with Content off has no `posts`/`contents`/`bookmarks` tables and no Content container bindings. The Core
 * code paths that used to need them must still build and answer.
 *
 * End-to-end proof (fresh install with Core + Widget only, every parameter-light GET route) is recorded in the
 * 2026-11-21 change-log entry; this test pins the code paths that failed there.
 */
final class CoreWithoutContentModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $content = Mockery::mock(\Nwidart\Modules\Module::class);
        $content->shouldReceive('isEnabled')->andReturn(false);
        Module::shouldReceive('find')->andReturnUsing(
            static fn (string $name) => strtolower($name) === 'content' ? $content : null
        );

        $this->app->offsetUnset(ContentInterface::class);
        $this->app->forgetInstance(ContentInterface::class);
    }

    public function test_the_content_tables_are_absent(): void
    {
        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertFalse(Schema::hasTable('contents'));
    }

    public function test_the_website_ajax_controller_builds_without_the_content_binding(): void
    {
        // Every ajax endpoint (contact, comment, register, language) failed to build while it injected ContentInterface.
        $this->assertInstanceOf(AjaxController::class, $this->app->make(AjaxController::class));
    }

    public function test_the_province_admin_builds_without_the_content_binding_and_skips_content_blocks(): void
    {
        $controller = $this->app->make(ProvinceController::class);

        $this->assertNull((new ReflectionProperty($controller, 'content'))->getValue($controller));
    }

    public function test_the_latest_posts_widget_query_answers_empty(): void
    {
        $this->assertTrue(WidgetQueryService::getQueryData('post.latest', 5)->isEmpty());
    }
}
