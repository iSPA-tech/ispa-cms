<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Support;

use Cms\Core\Models\Widget;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\SiteLayout\Drafts\WidgetDraftUsageFingerprint;

/**
 * The one projection of a Layout Draft returned by `GET /layouts/{uuid}/draft`, `PUT
 * /layouts/{uuid}/draft` (placements only) and the placement content routes, so their shapes
 * never drift apart. Expects `placements.instanceVersion.instance.currentVersion` loaded.
 */
final class WidgetLayoutDraftPayload
{
    /**
     * @return array{version: int, lock_version: int, placements: list<array<string, mixed>>, instance_fingerprints: array<string, string>}
     */
    public static function draft(WidgetLayoutVersion $draft): array
    {
        return [
            'version' => $draft->version,
            'lock_version' => $draft->lock_version,
            'placements' => self::placements($draft),
            'instance_fingerprints' => self::instanceFingerprints($draft),
            'contents' => self::contents($draft),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function placements(WidgetLayoutVersion $draft): array
    {
        return $draft->placements
            ->sortBy('position')
            ->values()
            ->map(static fn (WidgetPlacement $placement): array => self::placement($placement))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function placement(WidgetPlacement $placement): array
    {
        $instanceVersion = $placement->instanceVersion;

        return [
            'uuid' => $placement->uuid,
            'slot_key' => $placement->slot_key,
            'scope_type' => $placement->scope_type,
            'scope_key' => $placement->scope_key,
            'target_type' => $placement->target_type,
            'target_id' => $placement->target_id,
            'widget_instance_uuid' => $instanceVersion?->instance?->uuid,
            'widget_instance_version' => $instanceVersion?->version,
            'position' => $placement->position,
            'enabled' => $placement->enabled,
            'rules' => $placement->rules ?? [],
        ];
    }

    /**
     * What each placement shows, keyed `"<widget_instance_uuid>:<widget_instance_version>"` (the two
     * fields every placement row carries): the pinned version's widget, design, field set and values,
     * the widget's name and whether Core blocks it. The area editor reads placement content from
     * here, so it never calls the `/instances` routes (which need `widget_instance.*` permissions an
     * area editor does not have).
     *
     * @return array<string, array{widget_id: int, widget_theme_id: int, widget_name: ?string, widget_display_name: ?string, schema: array<string, mixed>, values: array<string, mixed>, is_blocked: bool}>
     */
    private static function contents(WidgetLayoutVersion $draft): array
    {
        $versions = [];
        foreach ($draft->placements as $placement) {
            $version = $placement->instanceVersion;
            $instance = $version?->instance;
            if ($version !== null && $instance !== null) {
                $versions["{$instance->uuid}:{$version->version}"] = $version;
            }
        }

        if ($versions === []) {
            return [];
        }

        $widgets = Widget::query()
            ->whereIn('id', array_unique(array_map(static fn ($version): int => (int) $version->widget_id, $versions)))
            ->get(['id', 'name', 'name_translations', 'blocked_at'])
            ->keyBy('id');

        $contents = [];
        foreach ($versions as $key => $version) {
            $widget = $widgets->get((int) $version->widget_id);
            $contents[$key] = [
                'widget_id' => (int) $version->widget_id,
                'widget_theme_id' => (int) $version->widget_theme_id,
                'widget_name' => $widget === null ? null : (string) $widget->name,
                // The name in the request locale.
                'widget_display_name' => $widget?->localizedName(),
                'schema' => $version->schema ?? [],
                'values' => $version->values ?? [],
                'is_blocked' => $widget?->isBlocked() === true,
            ];
        }

        return $contents;
    }

    /**
     * Per referenced Instance, the repin `usage_fingerprint` computed through the same
     * {@see WidgetDraftUsageFingerprint} primitive `POST .../versions-and-repin` validates, so a
     * client echoing it back never trips a false WIDGET_USAGE_CHANGED.
     *
     * @return array<string, string>
     */
    private static function instanceFingerprints(WidgetLayoutVersion $draft): array
    {
        $instancesById = [];
        $placementUuidsByInstanceId = [];

        foreach ($draft->placements as $placement) {
            $instance = $placement->instanceVersion?->instance;
            if ($instance === null) {
                continue;
            }

            $instancesById[$instance->id] = $instance;
            $placementUuidsByInstanceId[$instance->id][] = $placement->uuid;
        }

        $fingerprints = [];
        foreach ($instancesById as $instanceId => $instance) {
            $currentVersion = $instance->currentVersion?->version;

            $fingerprints[$instance->uuid] = WidgetDraftUsageFingerprint::compute(
                $draft->version,
                $draft->lock_version,
                $currentVersion === null ? null : (int) $currentVersion,
                $placementUuidsByInstanceId[$instanceId],
            );
        }

        return $fingerprints;
    }
}
