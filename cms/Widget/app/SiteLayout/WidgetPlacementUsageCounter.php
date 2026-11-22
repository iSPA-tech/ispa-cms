<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout;

use App\Contracts\Widget\WidgetAreaUsageCounter;
use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Support\Facades\DB;

/**
 * Shared-area usage of widgets for the widget library (binds the root {@see WidgetAreaUsageCounter}).
 *
 * Counts distinct placements of the CURRENT project (server-owned, {@see WidgetProjectContextResolver}) in each
 * layout's current draft and live release whose pinned instance version shows the widget (`widget_id`).
 *
 * Publishing and draft creation copy placements as new rows, so the same placement appears once in the draft and once
 * in the release with different ids and uuids. A placement is therefore identified by its layout, its instance and
 * where it sits (slot, scope, target), not by its row: present in both the draft and the release it counts ONCE.
 * Position is left out so a reorder in the draft does not double count. Consequences: a placement moved to another
 * slot or scope in the draft counts twice until publish; a legacy instance placed twice in the same slot and scope
 * counts once.
 */
final class WidgetPlacementUsageCounter implements WidgetAreaUsageCounter
{
    public function __construct(private readonly WidgetProjectContextResolver $projectContext)
    {
    }

    public function count(int $widgetId): int
    {
        return $this->countMany([$widgetId])[$widgetId] ?? 0;
    }

    public function countMany(array $widgetIds): array
    {
        $widgetIds = array_values(array_unique(array_map('intval', $widgetIds)));
        $counts = array_fill_keys($widgetIds, 0);
        if ($widgetIds === []) {
            return $counts;
        }

        [$rows, $layoutOfVersion] = $this->currentPlacements($widgetIds);

        $seen = [];
        foreach ($rows as $row) {
            $identity = implode("\x1F", [
                $layoutOfVersion[(int) $row->layout_version_id],
                $row->instance_id,
                $row->slot_key,
                $row->scope_type,
                $row->scope_identity,
                $row->scope_key,
                $row->target_type,
                $row->target_id,
            ]);
            $seen[(int) $row->widget_id][$identity] = true;
        }
        foreach ($seen as $widgetId => $identities) {
            $counts[$widgetId] = count($identities);
        }

        return $counts;
    }

    public function themeIdsInUse(int $widgetId): array
    {
        [$rows] = $this->currentPlacements([$widgetId]);
        $ids = array_values(array_unique(array_map(static fn ($row): int => (int) $row->widget_theme_id, $rows->all())));
        sort($ids);

        return $ids;
    }

    /**
     * Placement rows of the current project's current draft and live layout versions that show the given widgets.
     *
     * @param list<int> $widgetIds
     * @return array{0: \Illuminate\Support\Collection<int, object>, 1: array<int, int>} rows, layout id per layout version id
     */
    private function currentPlacements(array $widgetIds): array
    {
        $project = $this->projectContext->resolve()->projectName;
        $layoutOfVersion = [];
        $layouts = DB::table(WidgetTableConstant::LAYOUT)
            ->where('project_name', $project)
            ->get(['id', 'current_draft_version_id', 'current_published_version_id']);
        foreach ($layouts as $layout) {
            foreach ([$layout->current_draft_version_id, $layout->current_published_version_id] as $versionId) {
                if ($versionId !== null) {
                    $layoutOfVersion[(int) $versionId] = (int) $layout->id;
                }
            }
        }
        if ($layoutOfVersion === []) {
            return [collect(), []];
        }

        $rows = DB::table(WidgetTableConstant::PLACEMENT . ' as p')
            ->join(WidgetTableConstant::INSTANCE_VERSION . ' as iv', 'iv.id', '=', 'p.widget_instance_version_id')
            ->where('p.project_name', $project)
            ->where('iv.project_name', $project)
            ->whereIn('p.layout_version_id', array_keys($layoutOfVersion))
            ->whereIn('iv.widget_id', $widgetIds)
            ->get(['p.layout_version_id', 'iv.widget_id', 'iv.widget_theme_id', 'iv.instance_id', 'p.slot_key', 'p.scope_type', 'p.scope_identity', 'p.scope_key', 'p.target_type', 'p.target_id']);

        return [$rows, $layoutOfVersion];
    }
}
