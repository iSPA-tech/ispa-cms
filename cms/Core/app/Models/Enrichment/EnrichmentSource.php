<?php

namespace Cms\Core\Models\Enrichment;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class EnrichmentSource extends Model
{
    use HasUuid;
    use SoftDeletes;

    /** @var string */
    protected $table = CoreTableConstant::ENRICHMENT_SOURCES;

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'module',
        'target_type',
        'target_id',
        'target_uuid',
        'source_kind',
        'source_url',
        'source_url_hash',
        'adapter',
        'ai_schema_key',
        'status',
        'default_min_confidence',
        'cadence_interval_days',
        'min_interval_days',
        'max_interval_days',
        'next_run_at',
        'last_run_at',
        'last_success_at',
        'last_changed_at',
        'last_unchanged_at',
        'field_map',
        'confidence_thresholds',
        'confirmed_field_paths',
        'settings',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'target_id' => 'integer',
        'default_min_confidence' => 'float',
        'cadence_interval_days' => 'integer',
        'min_interval_days' => 'integer',
        'max_interval_days' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_changed_at' => 'datetime',
        'last_unchanged_at' => 'datetime',
        'field_map' => 'array',
        'confidence_thresholds' => 'array',
        'confirmed_field_paths' => 'array',
        'settings' => 'array',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
