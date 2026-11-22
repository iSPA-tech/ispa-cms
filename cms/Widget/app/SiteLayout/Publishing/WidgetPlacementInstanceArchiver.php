<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Publishing;

use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\SiteLayout\Drafts\WidgetPlacementContentService;

/**
 * Archives the instance of a placement that a publish removed (Unified Widget Catalog Part 2,
 * Task 3, Review Focus 3): no orphan instance stays active once nothing live or in progress
 * shows it.
 *
 * Candidates are the instances the previous live release showed, plus placement-owned
 * instances ({@see WidgetPlacementContentService::INSTANCE_NAME_PREFIX}) that no placement row
 * references any more (added and removed before a publish). A candidate stays active while the
 * current release or the current draft of any layout in the project still references it.
 * Historical releases are not counted: archiving is a status flag only (runtime and rollback
 * never read it), so an old release restored by rollback still renders.
 */
final class WidgetPlacementInstanceArchiver
{
    private const ACTIVE = 'active';

    private const ARCHIVED = 'archived';

    public function archiveUnplaced(string $projectName, ?int $previousReleaseId): int
    {
        $candidates = WidgetInstance::query()
            ->where('project_name', $projectName)
            ->where('status', self::ACTIVE)
            ->where(function ($query) use ($previousReleaseId): void {
                if ($previousReleaseId !== null) {
                    $query->whereIn('id', WidgetInstanceVersion::query()
                        ->select('instance_id')
                        ->whereIn('id', WidgetPlacement::query()->select('widget_instance_version_id')->where('layout_version_id', $previousReleaseId)));
                }

                $query->orWhere(function ($owned): void {
                    $owned->where('name', 'like', WidgetPlacementContentService::INSTANCE_NAME_PREFIX . '%')
                        ->whereNotIn('id', WidgetInstanceVersion::query()
                            ->select('instance_id')
                            ->whereIn('id', WidgetPlacement::query()->select('widget_instance_version_id')));
                });
            })
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            return 0;
        }

        $layouts = WidgetLayout::query()->where('project_name', $projectName)->get(['current_published_version_id', 'current_draft_version_id']);
        $liveVersionIds = $layouts->pluck('current_published_version_id')
            ->merge($layouts->pluck('current_draft_version_id'))
            ->filter()
            ->values()
            ->all();

        $stillShown = WidgetInstanceVersion::query()
            ->whereIn('instance_id', $candidates)
            ->whereIn('id', WidgetPlacement::query()->select('widget_instance_version_id')->whereIn('layout_version_id', $liveVersionIds))
            ->pluck('instance_id')
            ->all();

        $archive = array_values(array_diff($candidates, $stillShown));
        if ($archive === []) {
            return 0;
        }

        return WidgetInstance::query()->whereIn('id', $archive)->update(['status' => self::ARCHIVED]);
    }
}
