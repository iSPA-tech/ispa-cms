<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layout-version-owned Placement snapshot (plan Chunk 2 Task 13a;
 * architecture.md "widget_placements"; ADR
 * adr-widget-instance-scope-schema.md decision 10).
 *
 * `target_type`/`target_id` are their own columns -- target is never
 * encoded into `scope_key`. `scope_identity` is the canonical NOT NULL
 * cross-database exact-scope value (`global`; `template:<key>`;
 * `target:<type>:<id>`).
 */
final class WidgetPlacement extends Model
{
    use HasUuid;

    protected $table = WidgetTableConstant::PLACEMENT;

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_TEMPLATE = 'template';

    public const SCOPE_TARGET = 'target';

    protected $fillable = [
        'uuid',
        'project_name',
        'layout_version_id',
        'widget_instance_version_id',
        'slot_key',
        'scope_type',
        'scope_identity',
        'scope_key',
        'target_type',
        'target_id',
        'position',
        'enabled',
        'rules',
    ];

    protected $casts = [
        'position' => 'integer',
        'enabled' => 'boolean',
        'rules' => 'array',
    ];

    public function layoutVersion(): BelongsTo
    {
        return $this->belongsTo(WidgetLayoutVersion::class, 'layout_version_id');
    }

    public function instanceVersion(): BelongsTo
    {
        return $this->belongsTo(WidgetInstanceVersion::class, 'widget_instance_version_id');
    }
}
