<?php

declare(strict_types=1);

namespace Cms\Widget\Security;

use App\Contracts\Widget\DTO\WidgetMutationUsageSummary;
use App\Contracts\Widget\WidgetMutationGuard;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Widget\Models\WidgetInstanceVersion;

/**
 * Surfaces (never itself gates) retained-reference usage for a legacy
 * Widget Definition/Theme row referenced by ANY retained `cms/Widget`
 * Instance Version (plan Chunk 2 Task 16; architecture.md "Foreign keys
 * and deletion": "Deleting or archiving a Widget Definition or Theme
 * referenced by any retained Instance Version returns 409 with usage
 * summary, whether or not that Version is currently placed").
 *
 * "Retained" needs no join against Placements/Layouts/Drafts/Releases:
 * `widget_instance_versions` rows are immutable and NEVER deleted (no
 * `UPDATED_AT`, no soft-delete column -- {@see WidgetInstanceVersion}'s own
 * class docblock), so every row matching `widget_id`/`widget_theme_id` is,
 * by construction, a retained reference -- unplaced, archived-identity,
 * mutable Draft, superseded Draft, and Release history all count exactly
 * the same way this query already covers them.
 *
 * DELIBERATELY DOES NOT DENY VIA {@see self::permitsMutation()} (always
 * true): architecture.md's own language scopes the retained-reference
 * conflict to DELETING or ARCHIVING, never to ordinary field edits --
 * "Ordinary legacy Input changes affect only future Instance Versions...
 * they do not receive a blanket active-usage 409" generalizes across
 * legacy definitions (a content/name edit does not retroactively corrupt
 * an already-pinned immutable Version snapshot; only removing the row
 * breaks a future re-materialization). The single `permitsMutation(table,
 * id): bool` contract method carries no action-type distinction and is
 * shared by Core's update() AND destroy() call sites, so this guard's
 * ACTUAL denial signal lives entirely in {@see self::usageSummaryFor()},
 * which Core's destroy() paths consult EXPLICITLY and directly (see
 * WidgetController::retainedReferenceConflict()) rather than through the
 * generic isMutable()/permitsMutation() boolean every update() call also
 * passes through.
 *
 * Registered FIRST in {@see CompositeWidgetMutationGuard} (per this task's
 * file-list ordering instruction) for `usageSummaryFor()` priority, ahead
 * of the pre-existing Safe-Import lock-marker guard (which has no
 * usage-summary concept of its own).
 */
final class RetainedWidgetReferenceMutationGuard implements WidgetMutationGuard
{
    public function permitsMutation(string $table, int $id): bool
    {
        return true;
    }

    public function usageSummaryFor(string $table, int $id): ?WidgetMutationUsageSummary
    {
        $column = $this->referenceColumnFor($table);

        if ($column === null) {
            return null;
        }

        $matching = WidgetInstanceVersion::query()->where($column, $id);
        $count = (clone $matching)->count();

        if ($count === 0) {
            return null;
        }

        $maxId = (int) (clone $matching)->max('id');

        return new WidgetMutationUsageSummary(
            table: $table,
            id: $id,
            retainedVersionCount: $count,
            usageFingerprint: hash('sha256', sprintf('%s:%d:%d:%d', $table, $id, $count, $maxId)),
        );
    }

    /**
     * Ordinary legacy Input rows stay future-only (architecture.md
     * "Ordinary legacy Input changes affect only future Instance Versions
     * ... they do not receive a blanket active-usage 409") -- this guard
     * deliberately recognizes only the Widget/Theme tables, never
     * widget_inputs, so it never invents a lock this task's own DoD
     * forbids.
     */
    private function referenceColumnFor(string $table): ?string
    {
        return match ($table) {
            CoreTableConstant::WIDGET => 'widget_id',
            CoreTableConstant::WIDGET_THEME => 'widget_theme_id',
            default => null,
        };
    }
}
