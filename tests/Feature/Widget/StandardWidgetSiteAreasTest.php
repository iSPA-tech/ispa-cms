<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Enums\StatusEnum;
use App\Events\SaveTracking;
use App\Support\Widget\StandardWidgetSiteAreas;
use App\Support\Widget\WidgetRenderTarget;
use Cms\Content\Constants\ContentTableConstant;
use Cms\Content\Http\Controllers\Website\BlogController;
use Cms\Content\Models\Post;
use Cms\Content\Widget\PageWidgetTargetContextResolver;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mockery;
use Predis\ClientInterface;
use Tests\TestCase;

/**
 * Widget slots on every site, Phase A (2026-11-28): a project that registers
 * nothing of its own still gets the six standard Site Layout areas and the
 * shared Content "page" Target Context, and a page served by Content's
 * `reader` route announces itself, so its "this page only" placement renders
 * without the theme doing anything but call <x-widget-slot>.
 */
final class StandardWidgetSiteAreasTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'PlainProj';

    private const SLOT = 'page.after_content';

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        foreach (glob(sys_get_temp_dir() . '/plainproj-view-*') ?: [] as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /** The two callbacks AppServiceProvider and ContentServiceProvider queue on `booted`. */
    private function runSharedRegistration(): void
    {
        StandardWidgetSiteAreas::register($this->app);
        PageWidgetTargetContextResolver::registerTargetContext($this->app);
    }

    public function test_a_project_without_its_own_registration_gets_the_six_standard_areas_and_the_page_target(): void
    {
        $this->runSharedRegistration();
        $this->runSharedRegistration(); // idempotent: a second call (another provider) never throws

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $this->assertSame(array_keys(StandardWidgetSiteAreas::SLOTS), $slots->keys());

        $templates = $this->app->make(WidgetTemplateContextRegistry::class);
        $this->assertEqualsCanonicalizing(
            ['site.header.before', 'site.header.after', 'site.footer.before', 'site.footer.after'],
            $templates->slotKeysFor('site.shell'),
        );
        $this->assertEqualsCanonicalizing(['page.before_content', 'page.after_content'], $templates->slotKeysFor('page.static'));

        $definition = $this->app->make(WidgetTargetContextRegistry::class)->definitionFor('page');
        $this->assertNotNull($definition);
        $this->assertSame('page.static', $definition->templateContextKey);
        $this->assertSame(PageWidgetTargetContextResolver::class, $definition->resolverService);
    }

    public function test_the_slot_contracts_match_what_the_pilot_project_published(): void
    {
        $this->runSharedRegistration();

        // The pilot project's release v2 stored these semantic fields before the set became shared.
        $slots = $this->app->make(WidgetSlotRegistry::class);
        foreach (array_keys(StandardWidgetSiteAreas::SLOTS) as $key) {
            $this->assertSame([
                'cardinality' => 'stack',
                'supported_scope_types' => ['global', 'target', 'template'],
                'fallback' => null,
                'trust_lane' => 'legacy',
                'availability' => [],
            ], $slots->declarationFor($key)->semanticFields(), $key);
        }
    }

    public function test_a_project_can_turn_the_standard_areas_or_the_shared_page_target_off(): void
    {
        config(['content.widget_page_target' => false]);
        $this->runSharedRegistration();
        $this->assertSame(6, count($this->app->make(WidgetSlotRegistry::class)->keys()));
        $this->assertFalse($this->app->make(WidgetTargetContextRegistry::class)->isRegistered('page'));

        $provider = new WidgetServiceProvider($this->app);
        $provider->boot(); // fresh registries
        config(['ispa-cms.widget_site_areas' => false, 'content.widget_page_target' => true]);
        $this->runSharedRegistration();
        $this->assertSame([], $this->app->make(WidgetSlotRegistry::class)->keys());
        $this->assertFalse($this->app->make(WidgetTargetContextRegistry::class)->isRegistered('page'));
    }

    public function test_the_shared_page_resolver_keeps_the_uuid_404_403_and_readable_only_contract(): void
    {
        $this->createPostsTable();
        $published = $this->createPost('page', StatusEnum::PUBLISHED->value, 'Giới thiệu');
        $draft = $this->createPost('page', StatusEnum::DRAFT->value, 'Bản nháp');
        $article = $this->createPost('post', StatusEnum::PUBLISHED->value, 'Bài viết');

        $resolver = new PageWidgetTargetContextResolver();
        $context = new AuthorizedProjectContext(projectName: self::PROJECT);

        $resolved = $resolver->resolve($context, (string) $published->uuid);
        $this->assertSame('page', $resolved->targetType);
        $this->assertSame((string) $published->uuid, $resolved->targetId);
        $this->assertSame('reader', $resolved->routeName);

        $search = $resolver->search($context, new WidgetTargetSearchQuery(targetType: 'page', keywords: '', page: 1, perPage: 20));
        $this->assertSame([(string) $published->uuid], array_map(static fn ($item) => $item->targetId, $search->items));

        foreach (['missing' => (string) Str::uuid(), 'not a page' => (string) $article->uuid, 'numeric id' => (string) $published->id] as $case => $id) {
            try {
                $resolver->resolve($context, $id);
                $this->fail("{$case}: expected 404");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AuthorizationException::class);
        $resolver->resolve($context, (string) $draft->uuid);
    }

    public function test_a_page_served_by_reader_renders_its_target_placement(): void
    {
        Event::fake([SaveTracking::class]);
        $pageUuid = (string) Str::uuid();
        $this->publishReleaseWithTargetPlacement($pageUuid);
        $this->app->instance(WidgetSlotRuntime::class, $this->runtime());
        $this->projectWithLayoutOnly();

        $slotHtml = fn (): string => Blade::render('<x-widget-slot slot-key="' . self::SLOT . '"></x-widget-slot>');
        $this->assertStringNotContainsString('W8', $slotHtml());

        $this->serveThroughReader('post', (string) Str::uuid());
        $this->assertNull(app(WidgetRenderTarget::class)->targetId(), 'an article is not a page target');

        $this->serveThroughReader('page', $pageUuid);
        $this->assertSame('page', app(WidgetRenderTarget::class)->targetType());
        $this->assertSame($pageUuid, app(WidgetRenderTarget::class)->targetId());
        $this->assertStringContainsString('<i>W7</i><i>W8</i>', $slotHtml());
    }

    // -----------------------------------------------------------------

    private function serveThroughReader(string $type, string $uuid): void
    {
        $post = new Post();
        $post->forceFill(['id' => 1, 'uuid' => $uuid, 'type' => $type, 'name' => 'Giới thiệu', 'content' => []]);
        $slug = Mockery::mock(SlugInterface::class);
        $slug->shouldReceive('findBy')->andReturn((object) ['target_id' => 1, 'target' => $post]);
        $this->app->instance(SlugInterface::class, $slug);

        $this->app->make(BlogController::class)->reader('gioi-thieu');
    }

    private function projectWithLayoutOnly(): void
    {
        $dir = sys_get_temp_dir() . '/plainproj-view-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir . '/layout');
        File::put($dir . '/layout/index.blade.php', '<html>@yield("content")</html>');
        View::addNamespace('plainproj', $dir);
        config(['ispa-cms.folder' => 'plainproj::']);
    }

    private function runtime(): WidgetSlotRuntimeService
    {
        $redis = Mockery::mock(ClientInterface::class);
        $redis->shouldIgnoreMissing(); // every cache get() misses -> DB path

        $this->runSharedRegistration();

        return new WidgetSlotRuntimeService(
            app(WidgetSlotRegistry::class),
            null,
            new WidgetPlacementCache($redis),
            null,
            new class implements WidgetRenderer {
                public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
                {
                    return new WidgetRenderResult(html: '<i>W' . $spec->widgetId . '</i>', ok: true);
                }
            },
            app(WidgetTemplateContextRegistry::class),
        );
    }

    /** One release: a global placement (W7) and a placement for one page only (W8) in page.after_content. */
    private function publishReleaseWithTargetPlacement(string $pageUuid): void
    {
        $blob = WidgetArtifactBlob::query()->forceCreate([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('b', 64),
            'storage_disk' => 'local',
            'storage_key' => 'test/blob-plain',
            'byte_size' => 10,
            'media_type' => 'text/html',
            'checksum' => 'sha256:' . str_repeat('b', 64),
        ]);
        $manifestId = (int) WidgetArtifactManifest::query()->forceCreate([
            'project_name' => self::PROJECT,
            'manifest_hash' => str_repeat('c', 64),
            'artifact_blob_id' => $blob->id,
            'generator_version' => 'test',
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'applicability' => [self::PROJECT],
            'executable_surface_fingerprint_hash' => str_repeat('d', 64),
            'canonical_schema_hash' => str_repeat('e', 64),
            'provider_contracts' => [],
            'trust_policy_version' => 'test',
            'trust_lane' => 'trusted',
            'eligibility_state' => 'approved',
            'blocked' => false,
        ])->id;

        $layout = WidgetLayout::query()->forceCreate(['uuid' => (string) Str::uuid(), 'project_name' => self::PROJECT, 'key' => 'site']);
        $release = WidgetLayoutVersion::query()->forceCreate([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'state' => 'release',
            'lock_version' => 0,
            'registry_contracts' => [],
            'registry_contract_hash' => str_repeat('f', 64),
            'published_at' => now(),
        ]);
        $layout->forceFill(['current_published_version_id' => $release->id])->save();

        foreach ([[7, 'global', null, null, 'global'], [8, 'target', 'page', $pageUuid, 'target:page:' . $pageUuid]] as $position => [$widgetId, $scope, $targetType, $targetId, $identity]) {
            $instance = WidgetInstance::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'project_name' => self::PROJECT,
                'name' => 'Instance ' . $widgetId,
                'status' => 'active',
            ]);
            $version = WidgetInstanceVersion::query()->forceCreate([
                'instance_id' => $instance->id,
                'project_name' => self::PROJECT,
                'version' => 1,
                'widget_id' => $widgetId,
                'widget_theme_id' => 1,
                'renderer_key' => 'legacy',
                'renderer_version' => '1',
                'trust_classification' => 'trusted',
                'schema' => [],
                'schema_hash' => str_repeat('a', 64),
                'values' => [],
                'provider_refs' => [],
                'artifact_manifest_id' => $manifestId,
            ]);
            WidgetPlacement::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'project_name' => self::PROJECT,
                'layout_version_id' => $release->id,
                'widget_instance_version_id' => $version->id,
                'slot_key' => self::SLOT,
                'scope_type' => $scope,
                'scope_identity' => $identity,
                'scope_key' => null,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'position' => $position,
                'enabled' => true,
                'rules' => [],
            ]);
        }
    }

    private function createPostsTable(): void
    {
        // posts.status and posts.language_id are foreign keys: seed the two status rows and one language.
        DB::table(CoreTableConstant::ENUMERATES)->insertOrIgnore([
            ['name' => 'Published', 'enum_key' => StatusEnum::PUBLISHED->value, 'type' => 'status'],
            ['name' => 'Draft', 'enum_key' => StatusEnum::DRAFT->value, 'type' => 'status'],
        ]);
        DB::table(CoreTableConstant::LANGUAGES)->insertOrIgnore(['id' => 1, 'name' => 'Vietnamese', 'path' => 'vi']);
        if (Schema::hasTable(ContentTableConstant::POSTS)) {
            return;
        }
        Schema::create(ContentTableConstant::POSTS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->string('name')->nullable();
            $table->longText('content')->nullable();
            $table->string('type', 20)->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->json('config')->nullable();
            $table->string('status', 50)->nullable();
            $table->dateTimeTz('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createPost(string $type, string $status, string $name): Post
    {
        $post = new Post();
        $post->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'content' => [],
            'type' => $type,
            'language_id' => 1,
            'config' => [],
            'status' => $status,
        ]);
        Post::withoutEvents(static fn () => $post->save());

        return $post;
    }
}
