<?php

declare(strict_types=1);

namespace Cms\Widget\Constants;

/**
 * Central table-name registry for the Widget Site Layout schema (plan
 * Chunk 2 Task 13a, 
 * lines 724-775; architecture.md "Persistence Model").
 *
 * Exactly eight module-owned tables exist -- {@see self::ALL} is the single
 * source of truth both migrations/models and the schema test use to prove
 * that count, so a ninth table added without updating this list fails the
 * schema test rather than silently drifting.
 */
final class WidgetTableConstant
{
    public const ARTIFACT_BLOB = 'widget_artifact_blobs';

    public const ARTIFACT_MANIFEST = 'widget_artifact_manifests';

    public const INSTANCE = 'widget_instances';

    public const INSTANCE_VERSION = 'widget_instance_versions';

    public const LAYOUT = 'widget_layouts';

    public const LAYOUT_VERSION = 'widget_layout_versions';

    public const PLACEMENT = 'widget_placements';

    public const IDEMPOTENCY_KEY = 'widget_idempotency_keys';

    /**
     * @return list<string> every module-owned table name, exactly eight
     */
    public static function all(): array
    {
        return [
            self::ARTIFACT_BLOB,
            self::ARTIFACT_MANIFEST,
            self::INSTANCE,
            self::INSTANCE_VERSION,
            self::LAYOUT,
            self::LAYOUT_VERSION,
            self::PLACEMENT,
            self::IDEMPOTENCY_KEY,
        ];
    }
}
