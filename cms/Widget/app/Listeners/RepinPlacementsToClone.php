<?php

declare(strict_types=1);

namespace Cms\Widget\Listeners;

use App\Events\Widget\WidgetCloned;
use Cms\Core\Models\Widget;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService;
use Cms\Widget\SiteLayout\Drafts\WidgetPlacementContentService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Area placements follow a site clone (Unified Widget Catalog Part 2, Task 2).
 *
 * When a hub widget is cloned for the site, every placement in the CURRENT DRAFT of each layout of
 * the cloning project (server-owned, {@see WidgetProjectContextResolver}) whose
 * pinned instance version shows the hub widget is moved to the clone: same values, theme mapped
 * through {@see WidgetCloned::$themeIdMap}. Each move goes through
 * {@see WidgetPlacementContentService::updateContent()}, i.e. the existing content-edit path: a
 * placement that alone owns its instance gets a new version repinned in the draft
 * ({@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft}); a legacy instance shared by
 * several placements is forked for the moved placement. Both paths approve the clone's manifest for
 * the acting user (automatic approval), so this listener does not. Every move bumps the draft's
 * `lock_version`, so an editor tab opened before the clone gets the usual WIDGET_DRAFT_STALE.
 *
 * Published releases are never touched: the site shows the clone in areas after "Xuất bản".
 * Every layout always has a current draft (bootstrap, publish and rollback each leave one), so no
 * draft is created here; a layout without one is skipped.
 *
 * Runs synchronously: the event is dispatched after the clone transaction commits, and the admin
 * sees the moved placements on the next draft read. If another save makes the draft stale between
 * two moves, the draft is re-read and the remaining placements are retried once. A failure on one
 * layout is reported and never undoes the clone or stops the other layouts.
 */
final class RepinPlacementsToClone
{
    public function __construct(
        private readonly WidgetLayoutDraftService $drafts,
        private readonly WidgetPlacementContentService $content,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function handle(WidgetCloned $event): void
    {
        $hubId = Widget::query()->where('widget_key', $event->hubWidgetKey)->value('id');
        if ($hubId === null || $event->themeIdMap === []) {
            return;
        }

        $layouts = WidgetLayout::query()
            ->where('project_name', $this->projectContext->resolve()->projectName)
            ->whereNotNull('current_draft_version_id')
            ->orderBy('id')
            ->get();

        foreach ($layouts as $layout) {
            try {
                $this->repinLayout($layout, (int) $hubId, $event);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function repinLayout(WidgetLayout $layout, int $hubId, WidgetCloned $event): void
    {
        $projectName = (string) $layout->project_name;
        $draft = $this->drafts->findCurrentDraft($projectName, (string) $layout->uuid);
        $retried = false;

        // One placement per step: a fork re-saves the draft and regenerates placement uuids, so the
        // draft is re-read each time. Each successful step removes at least one hub placement.
        $steps = $draft === null ? 0 : count($this->hubPlacements($draft, $hubId, $event));
        for ($step = 0; $step < $steps && $draft !== null; $step++) {
            $placement = $this->hubPlacements($draft, $hubId, $event)[0] ?? null;
            if ($placement === null) {
                return;
            }

            $pinned = $placement->instanceVersion;
            $themeId = (int) $event->themeIdMap[(int) $pinned->widget_theme_id];

            $result = $this->content->updateContent(
                $projectName,
                (string) $layout->uuid,
                (string) $placement->uuid,
                $event->userId,
                'widget-cloned:' . hash('sha256', $event->cloneWidgetId . '|' . $layout->uuid . '|' . $placement->uuid . '|' . $draft->lock_version),
                (int) $draft->lock_version,
                is_array($pinned->values) ? $pinned->values : [],
                $themeId,
                $event->cloneWidgetId,
            );

            if (!$retried && in_array($result->status, ['stale', 'draft_stale'], true)) {
                // Another save moved the draft on: re-read it and retry the remaining placements once.
                $retried = true;
                $draft = $this->drafts->findCurrentDraft($projectName, (string) $layout->uuid);
                $steps = $step + 1 + ($draft === null ? 0 : count($this->hubPlacements($draft, $hubId, $event)));

                continue;
            }

            if ($result->status !== 'ok') {
                Log::warning('Widget clone repin stopped for a layout.', [
                    'layout_uuid' => $layout->uuid,
                    'clone_widget_id' => $event->cloneWidgetId,
                    'status' => $result->status,
                ]);

                return;
            }

            $draft = $this->drafts->findCurrentDraft($projectName, (string) $layout->uuid);
        }
    }

    /**
     * Draft placements showing the hub widget in a theme the clone has, by position.
     *
     * @return list<WidgetPlacement>
     */
    private function hubPlacements(WidgetLayoutVersion $draft, int $hubId, WidgetCloned $event): array
    {
        return $draft->placements
            ->filter(static fn (WidgetPlacement $p): bool => $p->instanceVersion !== null
                && (int) $p->instanceVersion->widget_id === $hubId
                && isset($event->themeIdMap[(int) $p->instanceVersion->widget_theme_id]))
            ->sortBy('position')
            ->values()
            ->all();
    }
}
