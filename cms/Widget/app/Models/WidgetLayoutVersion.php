<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Draft or released layout snapshot (plan Chunk 2 Task 13a; architecture.md
 * "widget_layout_versions").
 *
 * `state` is a plain string (draft|superseded|release) -- no native DB enum;
 * the three-value contract is enforced at the application layer only (later
 * tasks). Only the row referenced by the owning Layout's
 * current_draft_version_id is treated as mutable, by convention -- this
 * model does not itself block updates to any other row (service-layer
 * responsibility). `registry_contracts`/`eligibility_snapshot` are JSON
 * snapshots; canonical field immutability on Release rows is likewise a
 * later, service-layer guarantee.
 */
final class WidgetLayoutVersion extends Model
{
    protected $table = WidgetTableConstant::LAYOUT_VERSION;

    public const STATE_DRAFT = 'draft';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_RELEASE = 'release';

    protected $fillable = [
        'layout_id',
        'project_name',
        'version',
        'state',
        'based_on_version_id',
        'rollback_of_version_id',
        'lock_version',
        'registry_contracts',
        'registry_contract_hash',
        'eligibility_snapshot',
        'note',
        'actor_id',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'lock_version' => 'integer',
        'registry_contracts' => 'array',
        'eligibility_snapshot' => 'array',
        'published_at' => 'datetime',
    ];

    public function layout(): BelongsTo
    {
        return $this->belongsTo(WidgetLayout::class, 'layout_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_version_id');
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_version_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(WidgetPlacement::class, 'layout_version_id');
    }
}
