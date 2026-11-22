<?php

namespace Cms\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadR2Object extends Model
{
    protected $fillable = [
        'upload_id', 'relative_path', 'source_disk', 'kind',
        'source_size', 'source_mtime', 'source_sha256', 'previous_sha256',
        'status', 'attempts', 'last_error', 'synced_at', 'head_checked_at',
        'verified_at', 'evicted_at', 'next_retry_at', 'needs_cache_purge',
    ];

    protected $casts = [
        'source_mtime' => 'datetime',
        'synced_at' => 'datetime',
        'head_checked_at' => 'datetime',
        'verified_at' => 'datetime',
        'evicted_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'needs_cache_purge' => 'boolean',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
