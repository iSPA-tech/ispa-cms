<?php

namespace Cms\Core\Models\PublishedData;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class PublishedDataEmbedding extends Model
{
    use HasUuid;

    /** @var string */
    protected $table = CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS;

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'source_cluster_id',
        'candidate_id',
        'module',
        'profile_slug',
        'owner_type',
        'owner_uuid',
        'embedding_purpose',
        'language_code',
        'text_hash',
        'embedding_model',
        'embedding_model_version',
        'embedding_dimensions',
        'embedding_json',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source_cluster_id' => 'integer',
        'candidate_id' => 'integer',
        'embedding_dimensions' => 'integer',
        'embedding_json' => 'array',
        'metadata' => 'array',
    ];

    public function sourceCluster(): BelongsTo
    {
        return $this->belongsTo(PublishedDataSourceCluster::class, 'source_cluster_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(PublishedDataCandidate::class, 'candidate_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
