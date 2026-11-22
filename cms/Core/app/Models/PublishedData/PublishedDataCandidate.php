<?php

namespace Cms\Core\Models\PublishedData;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

final class PublishedDataCandidate extends Model
{
    use HasUuid;

    /** @var string */
    protected $table = CoreTableConstant::PUBLISHED_DATA_CANDIDATES;

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'source_cluster_id',
        'module',
        'profile_slug',
        'candidate_type',
        'target_type',
        'source',
        'external_type',
        'external_id',
        'external_key',
        'identity_hash',
        'source_label',
        'source_url',
        'source_url_hash',
        'canonical_key',
        'canonical_hash',
        'content_hash',
        'normalized_hash',
        'language_code',
        'status',
        'proposed_action',
        'match_status',
        'match_score',
        'confidence_score',
        'raw_payload',
        'candidate_payload',
        'normalized_payload',
        'evidence_payload',
        'match_payload',
        'decision_payload',
        'quality_payload',
        'quality_score',
        'strict_verification_required',
        'publish_ready',
        'canonical_target_type',
        'canonical_target_id',
        'canonical_target_uuid',
        'reviewed_at',
        'published_at',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source_cluster_id' => 'integer',
        'match_score' => 'float',
        'confidence_score' => 'float',
        'raw_payload' => 'array',
        'quality_payload' => 'array',
        'candidate_payload' => 'array',
        'normalized_payload' => 'array',
        'evidence_payload' => 'array',
        'match_payload' => 'array',
        'decision_payload' => 'array',
        'quality_score' => 'float',
        'strict_verification_required' => 'boolean',
        'publish_ready' => 'boolean',
        'canonical_target_id' => 'integer',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function sourceCluster(): BelongsTo
    {
        return $this->belongsTo(PublishedDataSourceCluster::class, 'source_cluster_id');
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(PublishedDataEmbedding::class, 'candidate_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
