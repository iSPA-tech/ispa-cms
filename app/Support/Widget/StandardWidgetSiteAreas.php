<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use Illuminate\Contracts\Container\Container;

/**
 * The standard Widget Site Layout areas every project gets ("Khu vực chung"):
 * six Slots and their two Template Contexts, declared once here instead of in
 * each project's provider.
 *
 * | Slot                                     | Template Context |
 * |------------------------------------------|------------------|
 * | site.header.before / site.header.after   | site.shell       |
 * | site.footer.before / site.footer.after   | site.shell       |
 * | page.before_content / page.after_content | page.static      |
 *
 * Registered only through the ROOT registrar contracts: with cms/Widget off
 * they resolve to NullWidgetExtensionRegistrar (no-op). Every registry treats
 * an identical re-registration as a no-op, so a project or module may call
 * {@see self::register()} again before adding its own Slots or a Target
 * Context that needs `page.static` -- call order between providers' booted
 * callbacks then never matters.
 *
 * A project turns the standard set off with `config(['ispa-cms.widget_site_areas' => false])` in its
 * CustomServiceProvider::register(); a project adds its own Slots through the same root contracts.
 *
 * The Slot semantic fields (cardinality stack, every scope type, no fallback,
 * legacy trust lane) and the Template Context slot sets are exactly what
 * the pilot project registered before this class existed, so the `registry_contracts`
 * stored on already published releases still compare as compatible.
 */
final class StandardWidgetSiteAreas
{
    public const TEMPLATE_SITE_SHELL = 'site.shell';

    public const TEMPLATE_PAGE_STATIC = 'page.static';

    /** Slot key => admin i18n label key (cms/Widget `widget_site_layout` group). */
    public const SLOTS = [
        'site.header.before' => 'widget_site_layout.slot.site_header_before',
        'site.header.after' => 'widget_site_layout.slot.site_header_after',
        'site.footer.before' => 'widget_site_layout.slot.site_footer_before',
        'site.footer.after' => 'widget_site_layout.slot.site_footer_after',
        'page.before_content' => 'widget_site_layout.slot.page_before_content',
        'page.after_content' => 'widget_site_layout.slot.page_after_content',
    ];

    public const TEMPLATE_CONTEXTS = [
        self::TEMPLATE_SITE_SHELL => ['site.header.before', 'site.header.after', 'site.footer.before', 'site.footer.after'],
        self::TEMPLATE_PAGE_STATIC => ['page.before_content', 'page.after_content'],
    ];

    public static function enabled(): bool
    {
        return config('ispa-cms.widget_site_areas', true) !== false;
    }

    /**
     * Registers the standard Slots and Template Contexts (idempotent).
     *
     * @return bool false when the active project turned the standard set off.
     */
    public static function register(Container $app): bool
    {
        if (!self::enabled()) {
            return false;
        }

        /** @var WidgetSlotRegistrar $slots */
        $slots = $app->make(WidgetSlotRegistrar::class);
        foreach (self::SLOTS as $key => $labelKey) {
            $slots->registerSlot(new WidgetSlotDefinition($key, $labelKey));
        }

        /** @var WidgetTemplateContextRegistrar $templateContexts */
        $templateContexts = $app->make(WidgetTemplateContextRegistrar::class);
        foreach (self::TEMPLATE_CONTEXTS as $templateKey => $slotKeys) {
            $templateContexts->registerTemplateContext($templateKey, $slotKeys);
        }

        return true;
    }
}
