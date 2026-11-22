<?php

namespace Cms\Core\Models\PublishedData;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

final class PublishedDataSourceCluster extends Model
{
    use HasUuid;

    /** @var string */
    protected $table = CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS;

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'module',
        'profile_slug',
        'cluster_type',
        'cluster_key',
        'language_code',
        'source_domain',
        'source_url',
        'source_url_hash',
        'status',
        'title',
        'source_count',
        'strict_verification_required',
        'allow_published_first',
        'canonical_target_type',
        'canonical_target_id',
        'canonical_target_uuid',
        'match_payload',
        'decision_payload',
        'synthesis_payload',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source_count' => 'integer',
        'strict_verification_required' => 'boolean',
        'allow_published_first' => 'boolean',
        'canonical_target_id' => 'integer',
        'match_payload' => 'array',
        'decision_payload' => 'array',
        'synthesis_payload' => 'array',
        'metadata' => 'array',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(PublishedDataCandidate::class, 'source_cluster_id');
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(PublishedDataEmbedding::class, 'source_cluster_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
