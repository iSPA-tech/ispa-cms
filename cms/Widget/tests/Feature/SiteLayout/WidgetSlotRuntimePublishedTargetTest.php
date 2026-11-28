<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Support\Widget\WidgetRenderTarget;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Mockery;
use Predis\ClientInterface;
use Tests\TestCase;

/**
 * Regression (a live site, 2026-11-28): a published "this page only"
 * (target-scoped) placement in the footer slot never rendered on the public
 * page, because the published path queried `scope_type = 'global'` only and
 * <x-widget-slot> passed no target. The preview overlay already resolved all
 * three tiers; the public path must resolve the same set.
 */
final class WidgetSlotRuntimePublishedTargetTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'TargetProj';

    private const SLOT = 'site.footer.after';

    private const TEMPLATE_CONTEXT = 'site.shell';

    private const PAGE_UUID = 'b56bf6dc-f132-42b0-a018-cd5af0c8dd3d';

    private int $manifestId;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'widget_site_layout.slot.site_footer_after',
            cardinality: 'stack',
            fallback: null,
        ));
        app(WidgetTemplateContextRegistry::class)->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);

        $blob = WidgetArtifactBlob::query()->forceCreate([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('b', 64),
            'storage_disk' => 'local',
            'storage_key' => 'test/blob-target',
            'byte_size' => 10,
            'media_type' => 'text/html',
            'checksum' => 'sha256:' . str_repeat('b', 64),
        ]);
        $this->manifestId = (int) WidgetArtifactManifest::query()->forceCreate([
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(): WidgetSlotRuntimeService
    {
        $redis = Mockery::mock(ClientInterface::class);
        $redis->shouldIgnoreMissing(); // every get() misses -> always the DB path

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

    private function instanceVersion(int $widgetId): WidgetInstanceVersion
    {
        $instance = WidgetInstance::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'project_name' => self::PROJECT,
            'name' => 'Instance ' . $widgetId,
            'status' => 'active',
        ]);

        return WidgetInstanceVersion::query()->forceCreate([
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
            'artifact_manifest_id' => $this->manifestId,
        ]);
    }

    /**
     * Publishes one Release holding a global (W7), a template (W9) and a
     * target page:PAGE_UUID (W8) placement in the same stack slot.
     */
    private function publishRelease(): void
    {
        $layout = WidgetLayout::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'project_name' => self::PROJECT,
            'key' => 'site',
        ]);
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

        $rows = [
            [7, 'global', null, null, null, 'global'],
            [9, 'template', self::TEMPLATE_CONTEXT, null, null, 'template:' . self::TEMPLATE_CONTEXT],
            [8, 'target', null, 'page', self::PAGE_UUID, 'target:page:' . self::PAGE_UUID],
        ];
        foreach ($rows as $position => [$widgetId, $scope, $scopeKey, $targetType, $targetId, $identity]) {
            WidgetPlacement::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'project_name' => self::PROJECT,
                'layout_version_id' => $release->id,
                'widget_instance_version_id' => $this->instanceVersion($widgetId)->id,
                'slot_key' => self::SLOT,
                'scope_type' => $scope,
                'scope_identity' => $identity,
                'scope_key' => $scopeKey,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'position' => $position,
                'enabled' => true,
                'rules' => [],
            ]);
        }
    }

    public function test_target_placement_renders_only_on_its_own_page(): void
    {
        $this->publishRelease();

        $onPage = $this->service()->render(self::SLOT, new WidgetRenderContext(
            projectName: self::PROJECT,
            targetType: 'page',
            targetId: self::PAGE_UUID,
        ));
        $this->assertTrue($onPage->ok);
        $this->assertSame('<i>W7</i><i>W9</i><i>W8</i>', $onPage->html);

        $otherPage = $this->service()->render(self::SLOT, new WidgetRenderContext(
            projectName: self::PROJECT,
            targetType: 'page',
            targetId: 'another-page-uuid',
        ));
        $this->assertSame('<i>W7</i><i>W9</i>', $otherPage->html);

        $noTarget = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));
        $this->assertSame('<i>W7</i><i>W9</i>', $noTarget->html);
    }

    public function test_slot_component_passes_the_request_page_target(): void
    {
        $this->publishRelease();
        config(['ispa-cms.folder_name' => self::PROJECT]);
        $this->app->instance(WidgetSlotRuntime::class, $this->service());

        $this->assertStringNotContainsString('W8', Blade::render('<x-widget-slot slot-key="' . self::SLOT . '"></x-widget-slot>'));

        app(WidgetRenderTarget::class)->set('page', self::PAGE_UUID);

        $this->assertStringContainsString('<i>W8</i>', Blade::render('<x-widget-slot slot-key="' . self::SLOT . '"></x-widget-slot>'));
    }
}
