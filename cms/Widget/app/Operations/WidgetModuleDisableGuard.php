<?php

declare(strict_types=1);

namespace Cms\Widget\Operations;

use App\Contracts\Modules\ModuleDisableGuard;
use Cms\Widget\Models\WidgetInstanceVersion;

/**
 * The Widget module's retained-reference predicate for managed disable
 * (plan Chunk 2 Task 22, DoD line 1201; docs/architecture/adr-widget-module-boundary.md
 * decision 5). Registered into the root, Widget-agnostic
 * {@see \App\Support\Modules\ModuleDisableGuardRegistry} by
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} -- Core never
 * imports this class; it only ever depends on the generic
 * {@see ModuleDisableGuard} contract Task 7 already froze.
 *
 * PREDICATE: blocks whenever ANY `widget_instance_versions` row exists at
 * all -- `WidgetInstanceVersion::query()->exists()`, no WHERE clause beyond
 * that. This is a deliberately UNSCOPED check, not an oversight:
 *
 * - Every row in this table always carries a non-null `widget_id`/
 *   `widget_theme_id` snapshot pinned to a Core-owned `widgets`/
 *   `widget_themes` row (that model's own docblock: "pinned legacy
 *   widget_id/widget_theme_id"). Before Chunk 4's Safe Import lands, Core's
 *   tables are the ONLY source of Widget Definitions, so "references a
 *   Core-owned Definition/Theme" is true of every row unconditionally --
 *   there is no narrower predicate to compute yet.
 * - Instance Versions are immutable and NEVER deleted once inserted (the
 *   model's own docblock: "Instance Versions are immutable and never
 *   deleted once inserted"), so a plain existence check already covers
 *   every version an Instance has ever accumulated, not merely its current
 *   pointer.
 * - "Including unplaced and superseded history" (DoD 1201) is satisfied BY
 *   CONSTRUCTION: this predicate applies no join against `widget_placements`
 *   (would exclude unplaced rows) and no filter against an Instance's
 *   `current_version_id` (would exclude superseded rows still older than
 *   the current one). Any such narrowing would silently let a module
 *   disable proceed while retained configuration still exists purely
 *   because nothing currently RENDERS it -- exactly the gap DoD 1201 rules
 *   out.
 *
 * Deliberately does NOT query the not-yet-created Safe Import provenance
 * table (Chunk 4) -- out of this task's scope per the coordinator brief.
 */
final class WidgetModuleDisableGuard implements ModuleDisableGuard
{
    public function moduleName(): string
    {
        return 'Widget';
    }

    public function evaluate(): ?string
    {
        if (WidgetInstanceVersion::query()->exists()) {
            return 'WIDGET_MODULE_IN_USE';
        }

        return null;
    }
}
