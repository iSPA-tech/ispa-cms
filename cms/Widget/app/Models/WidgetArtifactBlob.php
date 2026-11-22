<?php

declare(strict_types=1);

namespace Cms\Widget\Models;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable project-scoped byte metadata (plan Chunk 2 Task 13a;
 * architecture.md "widget_artifact_blobs").
 *
 * IMMUTABILITY DISCIPLINE: `$fillable` lists exactly the columns a Blob
 * publication flow may set at creation; `UPDATED_AT` is disabled because no
 * row is ever updated after insert (an existing hash with different bytes
 * is an integrity failure, never an overwrite). This class only declares
 * shape -- real write-path immutability enforcement (rejecting any UPDATE
 * attempt) is a service-layer responsibility for Task 13b's repository
 * adapters, not this model.
 */
final class WidgetArtifactBlob extends Model
{
    public const UPDATED_AT = null;

    protected $table = WidgetTableConstant::ARTIFACT_BLOB;

    protected $fillable = [
        'project_name',
        'artifact_hash',
        'storage_disk',
        'storage_key',
        'byte_size',
        'media_type',
        'checksum',
    ];

    protected $casts = [
        'byte_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function manifests(): HasMany
    {
        return $this->hasMany(WidgetArtifactManifest::class, 'artifact_blob_id');
    }
}
