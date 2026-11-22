<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable IDENTITY ONLY (plan Chunk 2 Task 13a; architecture.md
 * "widget_instances"; ADR adr-widget-instance-scope-schema.md decision 1,
 * D-007, rejecting S-002).
 *
 * Deliberately carries no widget_id, widget_theme_id, schema, values,
 * provider contract, or artifact identity -- that immutable configuration
 * lives entirely on {@see WidgetInstanceVersion}. `current_version_id` is
 * only ever set once a Version's exact Blob is verified and Manifest is
 * approved (Task 15a/15b); this model does not enforce that sequencing
 * itself (service-layer responsibility), only the shape.
 */
final class WidgetInstance extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = WidgetTableConstant::INSTANCE;

    protected $fillable = [
        'uuid',
        'project_name',
        'name',
        'status',
        'current_version_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'current_version_id' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(WidgetInstanceVersion::class, 'instance_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WidgetInstanceVersion::class, 'current_version_id');
    }
}
