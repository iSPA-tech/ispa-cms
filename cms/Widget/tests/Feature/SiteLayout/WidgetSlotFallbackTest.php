<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Contracts\Widget\WidgetSlotRuntime;
use App\Support\Widget\NullWidgetSlotRuntime;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Chunk 2 Task 15: fallback proof for the root `<x-widget-slot>` component
 * (plan lines 848-884, design notes; app/View/Components/WidgetSlot.php +
 * resources/views/components/widget-slot.blade.php, both root/Task-7-owned
 * and untouched by this task).
 *
 * Three states, per the plan's design notes verbatim:
 * - module booted + slot registered (with a declared fallback) => the
 *   RUNTIME's declared fallback renders, not the Blade-supplied one.
 * - unregistered slot (module booted) => empty (WidgetSlotRuntimeService
 *   answers ok=false, so the component falls through to its OWN
 *   Blade-supplied fallback slot content -- proven both with and without
 *   that content supplied).
 * - module-off => Null runtime (existing behavior re-pinned). The
 *   authoritative proof for this state is
 *   tests/Feature/Widget/WidgetModuleOffCompatibilityTest.php (Task 7,
 *   unmodified, still part of this task's required verification run); this
 *   file adds one direct confirmation of its own so module-off/empty proof
 *   does not live ONLY in a cross-referenced file.
 *
 * Module boot uses the same in-process `register()+boot()` seam
 * cms/Widget/tests/Feature/Rendering/WidgetRenderCompatibilityTest.php
 * established for Task 8 -- it exercises the real WidgetServiceProvider
 * class against the test container without touching modules_statuses.json
 * (the module stays disabled there by design).
 */
final class WidgetSlotFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function bootWidgetServiceProvider(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    private function registerSlotWithFallback(string $key, ?string $fallback): void
    {
        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: $key,
            labelKey: 'widget_site_layout.slot.' . str_replace('.', '_', $key),
            cardinality: 'stack',
            fallback: $fallback,
        ));
    }

    // -----------------------------------------------------------------
    // module-off => Null runtime (re-pin; authoritative proof is
    // WidgetModuleOffCompatibilityTest)
    // -----------------------------------------------------------------

    public function test_module_off_resolves_the_null_runtime_and_renders_the_blade_supplied_fallback(): void
    {
        // Widget is an enabled bundled module since 2026-07-20, so the container already holds the runtime
        // service. Re-apply the root default (AppServiceProvider) to model the module-off state.
        $this->app->singleton(WidgetSlotRuntime::class, NullWidgetSlotRuntime::class);

        $this->assertInstanceOf(NullWidgetSlotRuntime::class, app(WidgetSlotRuntime::class));

        $html = (string) Blade::render('<x-widget-slot slot-key="homepage.header">Fallback content</x-widget-slot>');

        $this->assertStringContainsString('Fallback content', $html);
    }

    // -----------------------------------------------------------------
    // module booted + slot registered => declared fallback
    // -----------------------------------------------------------------

    public function test_module_booted_rebinds_widget_slot_runtime_to_the_runtime_service(): void
    {
        $this->bootWidgetServiceProvider();

        $this->assertInstanceOf(WidgetSlotRuntimeService::class, app(WidgetSlotRuntime::class));
        $this->assertNotInstanceOf(NullWidgetSlotRuntime::class, app(WidgetSlotRuntime::class));
    }

    public function test_registered_slot_with_a_declared_fallback_renders_that_fallback_not_the_blade_slot(): void
    {
        $this->bootWidgetServiceProvider();
        $this->registerSlotWithFallback('homepage.header', '<div class="declared-fallback">Declared fallback</div>');

        $html = (string) Blade::render('<x-widget-slot slot-key="homepage.header">Blade-supplied fallback</x-widget-slot>');

        $this->assertStringContainsString('Declared fallback', $html);
        $this->assertStringNotContainsString('Blade-supplied fallback', $html);
    }

    // -----------------------------------------------------------------
    // unregistered slot (module booted) => empty
    // -----------------------------------------------------------------

    public function test_unregistered_slot_with_module_booted_renders_empty_without_a_blade_fallback(): void
    {
        $this->bootWidgetServiceProvider();
        // Deliberately never registered.

        $html = trim((string) Blade::render('<x-widget-slot slot-key="never.registered"></x-widget-slot>'));

        $this->assertSame('', $html);
    }

    public function test_unregistered_slot_with_module_booted_falls_through_to_the_blade_supplied_fallback(): void
    {
        $this->bootWidgetServiceProvider();

        $html = (string) Blade::render('<x-widget-slot slot-key="never.registered">Blade-supplied fallback</x-widget-slot>');

        $this->assertStringContainsString('Blade-supplied fallback', $html);
    }

    public function test_registered_slot_without_a_declared_fallback_falls_through_to_the_blade_supplied_fallback(): void
    {
        $this->bootWidgetServiceProvider();
        $this->registerSlotWithFallback('homepage.header', null);

        $html = (string) Blade::render('<x-widget-slot slot-key="homepage.header">Blade-supplied fallback</x-widget-slot>');

        $this->assertStringContainsString('Blade-supplied fallback', $html);
    }

    // -----------------------------------------------------------------
    // WidgetSlotRuntimeService result shape (direct, non-Blade proof)
    // -----------------------------------------------------------------

    public function test_runtime_service_returns_ok_false_and_empty_html_for_an_unregistered_slot(): void
    {
        $this->bootWidgetServiceProvider();

        $result = app(WidgetSlotRuntime::class)->render(
            'never.registered',
            new \App\Contracts\Widget\DTO\WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
    }

    public function test_runtime_service_returns_ok_true_and_the_declared_fallback_html_for_a_registered_slot(): void
    {
        $this->bootWidgetServiceProvider();
        $this->registerSlotWithFallback('homepage.header', 'Declared fallback text');

        $result = app(WidgetSlotRuntime::class)->render(
            'homepage.header',
            new \App\Contracts\Widget\DTO\WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertTrue($result->ok);
        $this->assertSame('Declared fallback text', $result->html);
    }
}
