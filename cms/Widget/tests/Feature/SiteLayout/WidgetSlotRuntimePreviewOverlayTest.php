<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Preview\WidgetPreviewContext;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preview-centric editor (redesign): the signed preview session's decrypted
 * overlay REPLACES published-Release resolution in
 * {@see WidgetSlotRuntimeService} while the request-scoped
 * {@see WidgetPreviewContext} is bound, and — when the session was minted
 * with an editor nonce — every rendered placement is wrapped in a
 * zero-layout hit-box marker carrying the composite identity
 * `slot_key::scope_identity::widget_instance_uuid` (the exact key shape
 * base-admin's placement-canvas-utils derives client-side).
 *
 * Uses the same in-process provider `register()+boot()` seam
 * WidgetSlotFallbackTest established, plus a deterministic fake
 * {@see WidgetRenderer} so the assertions never depend on the legacy
 * widget render pipeline's own output.
 */
final class WidgetSlotRuntimePreviewOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'OverlayProj';

    private const SLOT = 'page.before_content';

    private const TEMPLATE_CONTEXT = 'page.static';

    protected function tearDown(): void
    {
        app()->forgetInstance(WidgetPreviewContext::class);
        parent::tearDown();
    }

    private function bootWidgetServiceProvider(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'widget_site_layout.slot.page_before_content',
            cardinality: 'stack',
            fallback: '<em>declared-fallback</em>',
        ));
        app(WidgetTemplateContextRegistry::class)->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);
    }

    private function fakeRenderer(): WidgetRenderer
    {
        return new class implements WidgetRenderer {
            public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
            {
                return new WidgetRenderResult(html: '<span class="fake-widget">W' . $spec->widgetId . '</span>', ok: true);
            }
        };
    }

    private function service(): WidgetSlotRuntimeService
    {
        return new WidgetSlotRuntimeService(
            app(WidgetSlotRegistry::class),
            null,
            null,
            null,
            $this->fakeRenderer(),
            app(WidgetTemplateContextRegistry::class),
        );
    }

    /**
     * @return array{instance: WidgetInstance, version: WidgetInstanceVersion}
     */
    private function instanceFixture(string $uuid = 'aaaaaaaa-1111-2222-3333-444444444444'): array
    {
        // The version table's mandatory artifact_manifest_id FK requires the
        // real blob -> manifest chain (RefreshDatabase's transaction makes
        // sqlite's FK pragma un-toggleable mid-test). Approved + unblocked
        // manifest keeps the runtime-control read on its allow path.
        $blob = \Cms\Widget\Models\WidgetArtifactBlob::query()->forceCreate([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('b', 64),
            'storage_disk' => 'local',
            'storage_key' => 'test/blob-' . $uuid,
            'byte_size' => 10,
            'media_type' => 'text/html',
            'checksum' => 'sha256:' . str_repeat('b', 64),
        ]);

        $manifest = \Cms\Widget\Models\WidgetArtifactManifest::query()->forceCreate([
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
        ]);

        $instance = WidgetInstance::query()->forceCreate([
            'uuid' => $uuid,
            'project_name' => self::PROJECT,
            'name' => 'Overlay Instance',
            'status' => 'active',
        ]);

        $version = WidgetInstanceVersion::query()->forceCreate([
            'instance_id' => $instance->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'widget_id' => 7,
            'widget_theme_id' => 9,
            'renderer_key' => 'legacy',
            'renderer_version' => '1',
            'trust_classification' => 'trusted',
            'schema' => [],
            'schema_hash' => str_repeat('a', 64),
            'values' => ['title' => 'Hello'],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifest->id,
        ]);

        return ['instance' => $instance, 'version' => $version];
    }

    /**
     * @param list<array<string, mixed>> $overlay
     */
    private function bindPreviewContext(array $overlay, ?string $editorNonce, string $targetId = 'page-1'): void
    {
        app()->instance(WidgetPreviewContext::class, new WidgetPreviewContext(
            projectName: self::PROJECT,
            layoutUuid: 'layout-uuid-1',
            locale: 'vi',
            templateContext: self::TEMPLATE_CONTEXT,
            targetType: 'page',
            targetId: $targetId,
            canonicalRouteName: 'test.route',
            canonicalRouteParameters: [],
            releaseBaseline: null,
            overlayPlacements: $overlay,
            manifestIds: [],
            fingerprint: 'fp',
            mintedBy: null,
            mintedAt: '2026-07-25T00:00:00+00:00',
            viewport: 'desktop',
            editorNonce: $editorNonce,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function overlayRow(WidgetInstanceVersion $version, array $overrides = []): array
    {
        return array_merge([
            'slot_key' => self::SLOT,
            'scope_type' => 'global',
            'scope_key' => null,
            'target_type' => null,
            'target_id' => null,
            'scope_identity' => 'global',
            'widget_instance_version_id' => $version->id,
            'position' => 0,
            'enabled' => true,
            'rules' => [],
        ], $overrides);
    }

    public function test_bound_preview_overlay_renders_without_markers_when_no_editor_nonce(): void
    {
        $this->bootWidgetServiceProvider();
        $fixture = $this->instanceFixture();
        $this->bindPreviewContext([$this->overlayRow($fixture['version'])], null);

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('<span class="fake-widget">W7</span>', $result->html);
        $this->assertStringNotContainsString('data-wpe-placement-key', $result->html);
    }

    public function test_editor_nonce_wraps_each_placement_in_a_composite_key_marker(): void
    {
        $this->bootWidgetServiceProvider();
        $fixture = $this->instanceFixture();
        $this->bindPreviewContext([$this->overlayRow($fixture['version'])], 'editor-nonce-1');

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $expectedKey = self::SLOT . '::global::' . $fixture['instance']->uuid;
        $this->assertStringContainsString('data-wpe-placement-key="' . $expectedKey . '"', $result->html);
        $this->assertStringContainsString('data-wpe-slot-key="' . self::SLOT . '"', $result->html);
        $this->assertStringContainsString('display:contents', $result->html);
        $this->assertStringContainsString('<span class="fake-widget">W7</span>', $result->html);
    }

    public function test_preview_overlay_replaces_published_resolution_for_an_empty_slot(): void
    {
        $this->bootWidgetServiceProvider();
        // Overlay bound but carries NOTHING for this slot: the slot must
        // fall to the declared fallback, never to any published rows.
        $this->bindPreviewContext([], null);

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $this->assertSame('<em>declared-fallback</em>', $result->html);
    }

    public function test_editor_nonce_wraps_every_area_in_an_area_marker_also_when_empty(): void
    {
        $this->bootWidgetServiceProvider();
        $this->bindPreviewContext([], 'editor-nonce-1');

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $this->assertSame(
            '<div data-wpe-area-key="' . self::SLOT . '" style="display:contents"><em>declared-fallback</em></div>',
            $result->html,
        );

        $fixture = $this->instanceFixture();
        $this->bindPreviewContext([$this->overlayRow($fixture['version'])], 'editor-nonce-1');

        $filled = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertStringStartsWith('<div data-wpe-area-key="' . self::SLOT . '"', $filled->html);
        $this->assertStringContainsString('data-wpe-placement-key=', $filled->html);
    }

    public function test_target_scoped_overlay_rows_resolve_only_for_the_minted_target(): void
    {
        $this->bootWidgetServiceProvider();
        $fixture = $this->instanceFixture();

        $matching = $this->overlayRow($fixture['version'], [
            'scope_type' => 'target',
            'target_type' => 'page',
            'target_id' => 'page-1',
            'scope_identity' => 'target:page:page-1',
        ]);
        $other = $this->overlayRow($fixture['version'], [
            'scope_type' => 'target',
            'target_type' => 'page',
            'target_id' => 'page-OTHER',
            'scope_identity' => 'target:page:page-OTHER',
        ]);

        $this->bindPreviewContext([$matching, $other], 'editor-nonce-1', 'page-1');

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('target:page:page-1', $result->html);
        $this->assertStringNotContainsString('target:page:page-OTHER', $result->html);
    }

    public function test_preview_for_a_different_project_is_ignored(): void
    {
        $this->bootWidgetServiceProvider();
        $fixture = $this->instanceFixture();
        $row = $this->overlayRow($fixture['version']);

        app()->instance(WidgetPreviewContext::class, new WidgetPreviewContext(
            projectName: 'SomeOtherProject',
            layoutUuid: 'layout-uuid-1',
            locale: 'vi',
            templateContext: self::TEMPLATE_CONTEXT,
            targetType: 'page',
            targetId: 'page-1',
            canonicalRouteName: 'test.route',
            canonicalRouteParameters: [],
            releaseBaseline: null,
            overlayPlacements: [$row],
            manifestIds: [],
            fingerprint: 'fp',
            mintedBy: null,
            mintedAt: '2026-07-25T00:00:00+00:00',
            viewport: 'desktop',
            editorNonce: 'editor-nonce-1',
        ));

        $result = $this->service()->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        // No published rows exist either, so the declared fallback wins —
        // the point is the OTHER project's overlay never leaks in.
        $this->assertSame('<em>declared-fallback</em>', $result->html);
    }
}
