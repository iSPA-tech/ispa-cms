<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Project-owned layout identity (plan Chunk 2 Task 13a; architecture.md
 * "widget_layouts").
 *
 * `current_draft_version_id`/`current_published_version_id` are only ever
 * set once their referenced {@see WidgetLayoutVersion} rows exist -- shape
 * only, sequencing is a service-layer concern for later tasks.
 */
final class WidgetLayout extends Model
{
    use HasUuid;

    protected $table = WidgetTableConstant::LAYOUT;

    protected $fillable = [
        'uuid',
        'project_name',
        'key',
        'current_draft_version_id',
        'current_published_version_id',
    ];

    protected $casts = [
        'current_draft_version_id' => 'integer',
        'current_published_version_id' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(WidgetLayoutVersion::class, 'layout_id');
    }

    public function currentDraft(): BelongsTo
    {
        return $this->belongsTo(WidgetLayoutVersion::class, 'current_draft_version_id');
    }

    public function currentPublished(): BelongsTo
    {
        return $this->belongsTo(WidgetLayoutVersion::class, 'current_published_version_id');
    }
}
