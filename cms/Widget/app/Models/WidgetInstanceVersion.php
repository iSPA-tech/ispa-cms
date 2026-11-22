<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The IMMUTABLE configured revision (plan Chunk 2 Task 13a; architecture.md
 * "widget_instance_versions"; ADR adr-widget-instance-scope-schema.md
 * decision 2, D-007).
 *
 * Owns everything identity does not: pinned legacy widget_id/widget_theme_id,
 * renderer identity, trust classification, canonical schema JSON + hash,
 * normalized values JSON, structured provider references JSON, and the
 * pinned Manifest. Deliberately has NO `artifact_status` (D-042).
 *
 * IMMUTABILITY DISCIPLINE: `UPDATED_AT` is disabled -- a Version is inserted
 * once and never updated. `$fillable` covers only the creation-time
 * attributes; real write-path immutability enforcement is a service-layer
 * responsibility for later tasks.
 */
final class WidgetInstanceVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = WidgetTableConstant::INSTANCE_VERSION;

    protected $fillable = [
        'instance_id',
        'project_name',
        'version',
        'widget_id',
        'widget_theme_id',
        'renderer_key',
        'renderer_version',
        'trust_classification',
        'schema',
        'schema_hash',
        'values',
        'provider_refs',
        'artifact_manifest_id',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'schema' => 'array',
        'values' => 'array',
        'provider_refs' => 'array',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WidgetInstance::class, 'instance_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(WidgetArtifactManifest::class, 'artifact_manifest_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(WidgetPlacement::class, 'widget_instance_version_id');
    }
}
