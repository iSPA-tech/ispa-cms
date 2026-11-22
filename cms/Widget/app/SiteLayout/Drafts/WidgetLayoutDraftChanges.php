<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Drafts;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use Cms\Core\Models\Widget;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What publishing the current draft would change on the site: the change list of the publish dialog
 * (`GET /layouts/{uuid}/draft/changes`), compared with the layout's live release.
 *
 * Publishing and draft creation copy placements as new rows, so rows are never matched by id or uuid.
 * Matching, in two passes (position is ignored everywhere, so a reorder is not a change):
 * 1. Same placement: same instance and same place (slot, scope, target) — the identity
 *    {@see \Cms\Widget\SiteLayout\WidgetPlacementUsageCounter} uses. A different pinned version or enabled
 *    flag is `changed`; otherwise unchanged and not listed.
 *    Rows with the same version and enabled flag pair first, so a reorder never crosses pairs.
 * 2. Forked content: among what is left, a draft and a live placement in the same place showing the same
 *    widget lineage (a site clone counts as the hub widget in its `cloned_from_widget_key`) are paired as
 *    `changed`. This covers an edit of a legacy instance shared by several placements, which forks a new
 *    instance for the edited placement (Task 3), also when the clone repin moves it to a site clone (Task 2).
 * Draft placements still unpaired are `added`; live placements still unpaired are `removed`. With no live
 * release every draft placement is `added`.
 *
 * Labels are plain Vietnamese for the admin (current app locale): slot label from the slot's label key,
 * the widget's catalog name, and a scope label ("Toàn website", "Theo loại trang · <page type>", "Riêng
 * trang này · <page title>"). A label whose translation is missing is null; `slot_label_key` and
 * `scope_type` are returned so the admin can translate it itself. A page title is shown only when the page
 * is readable; otherwise the page-type label of its target context is used.
 */
final class WidgetLayoutDraftChanges
{
    public function __construct(
        private readonly WidgetSlotRegistry $slots,
        private readonly WidgetTargetContextRegistry $targetContexts,
    ) {
    }

    /**
     * @return list<array<string, mixed>>|null null when the layout or its draft does not exist in the project
     */
    public function forLayout(string $projectName, string $layoutUuid): ?array
    {
        $layout = WidgetLayout::query()->where('project_name', $projectName)->where('uuid', $layoutUuid)->first();
        if ($layout === null || $layout->current_draft_version_id === null) {
            return null;
        }

        $draft = $this->placementsOf($projectName, (int) $layout->current_draft_version_id);
        $live = $layout->current_published_version_id === null
            ? collect()
            : $this->placementsOf($projectName, (int) $layout->current_published_version_id);

        $changes = [];

        $widgets = Widget::query()
            ->whereIn('id', $draft->concat($live)->map(fn (WidgetPlacement $p): int => $this->widgetId($p))->unique()->values()->all())
            ->get(['id', 'name', 'name_translations', 'widget_key', 'cloned_from_widget_key'])
            ->keyBy('id');

        // Pass 1: same instance, same place. Identical rows (same version and enabled flag) pair first, so a
        // reorder of two versions of one instance in one place is not reported.
        $liveByIdentity = $live->groupBy(fn (WidgetPlacement $p): string => $this->place($p) . "\x1F" . $this->instanceId($p));
        $unmatched = [];
        foreach ($draft as $placement) {
            if (!$this->takeLive($liveByIdentity, $placement, exact: true)) {
                $unmatched[] = $placement;
            }
        }
        $draftLeft = [];
        foreach ($unmatched as $placement) {
            if ($this->takeLive($liveByIdentity, $placement, exact: false)) {
                $changes[] = ['changed', $placement];
            } else {
                $draftLeft[] = $placement;
            }
        }

        // Pass 2: forked content — same place, same widget lineage (a site clone counts as its hub widget).
        $liveByPlaceWidget = $liveByIdentity->flatten(1)
            ->groupBy(fn (WidgetPlacement $p): string => $this->place($p) . "\x1F" . $this->lineage($p, $widgets));
        foreach ($draftLeft as $placement) {
            $match = $liveByPlaceWidget->get($this->place($placement) . "\x1F" . $this->lineage($placement, $widgets))?->shift();
            $changes[] = [$match === null ? 'added' : 'changed', $placement];
        }
        foreach ($liveByPlaceWidget->flatten(1) as $placement) {
            $changes[] = ['removed', $placement];
        }

        // A label in the request locale, like the slot and scope labels.
        $widgetNames = $widgets->map(static fn (Widget $widget): string => $widget->localizedName());
        $auth = new AuthorizedProjectContext($projectName);

        return array_map(fn (array $change): array => $this->row($change[0], $change[1], $widgetNames, $auth), $changes);
    }

    /**
     * @return Collection<int, WidgetPlacement>
     */
    private function placementsOf(string $projectName, int $versionId): Collection
    {
        return WidgetPlacement::query()
            ->where('project_name', $projectName)
            ->where('layout_version_id', $versionId)
            ->with('instanceVersion')
            ->orderBy('position')
            ->get();
    }

    private function place(WidgetPlacement $placement): string
    {
        return implode("\x1F", [
            $placement->slot_key,
            $placement->scope_type,
            $placement->scope_identity,
            $placement->scope_key,
            $placement->target_type,
            $placement->target_id,
        ]);
    }

    /**
     * Removes and reports the first live placement of the same instance in the same place (with the same
     * version and enabled flag when `$exact`).
     *
     * @param Collection<string, Collection<int, WidgetPlacement>> $liveByIdentity
     */
    private function takeLive(Collection $liveByIdentity, WidgetPlacement $placement, bool $exact): bool
    {
        $candidates = $liveByIdentity->get($this->place($placement) . "\x1F" . $this->instanceId($placement));
        $index = $candidates?->search(fn (WidgetPlacement $live): bool => !$exact || $this->sameContent($live, $placement));
        if ($index === null || $index === false) {
            return false;
        }
        $candidates->forget($index);

        return true;
    }

    private function sameContent(WidgetPlacement $live, WidgetPlacement $draft): bool
    {
        return (int) $live->widget_instance_version_id === (int) $draft->widget_instance_version_id
            && (bool) $live->enabled === (bool) $draft->enabled;
    }

    /**
     * The widget's family: a site clone answers with the hub widget key it was cloned from.
     *
     * @param Collection<int, Widget> $widgets
     */
    private function lineage(WidgetPlacement $placement, Collection $widgets): string
    {
        $widget = $widgets->get($this->widgetId($placement));

        return (string) ($widget?->cloned_from_widget_key ?? $widget?->widget_key ?? 'id:' . $this->widgetId($placement));
    }

    private function instanceId(WidgetPlacement $placement): int
    {
        return (int) $placement->instanceVersion?->instance_id;
    }

    private function widgetId(WidgetPlacement $placement): int
    {
        return (int) $placement->instanceVersion?->widget_id;
    }

    /**
     * @param Collection<int, string> $widgetNames
     * @return array<string, mixed>
     */
    private function row(string $kind, WidgetPlacement $placement, Collection $widgetNames, AuthorizedProjectContext $auth): array
    {
        $slotLabelKey = $this->slots->declarationFor((string) $placement->slot_key)?->labelKey;
        $name = $widgetNames->get($this->widgetId($placement));

        return [
            'kind' => $kind,
            'slot_key' => $placement->slot_key,
            'slot_label' => $slotLabelKey === null ? null : $this->translate($slotLabelKey),
            'slot_label_key' => $slotLabelKey,
            'widget_name' => $name === null ? null : (string) $name,
            'scope_type' => $placement->scope_type,
            'scope_label' => $this->scopeLabel($placement, $auth),
        ];
    }

    private function scopeLabel(WidgetPlacement $placement, AuthorizedProjectContext $auth): ?string
    {
        $base = $this->translate('widget_site_layout.scope.' . $placement->scope_type);
        if ($base === null) {
            return null;
        }

        $detail = match ($placement->scope_type) {
            WidgetPlacement::SCOPE_TEMPLATE => $this->translate('widget_site_layout.template_context.' . str_replace('.', '_', (string) $placement->scope_key)),
            WidgetPlacement::SCOPE_TARGET => $this->targetLabel((string) $placement->target_type, (string) $placement->target_id, $auth),
            default => null,
        };

        return $detail === null ? $base : $base . ' · ' . $detail;
    }

    private function targetLabel(string $targetType, string $targetId, AuthorizedProjectContext $auth): ?string
    {
        $definition = $this->targetContexts->definitionFor($targetType);
        if ($definition === null) {
            return null;
        }

        try {
            return $this->targetContexts->resolve($targetType, $auth, $targetId)->label;
        } catch (Throwable) {
            // Missing or unpublished page: name only its page type.
            return $this->translate($definition->labelKey);
        }
    }

    private function translate(string $key): ?string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
