<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One internal note on a registered admin record.
 *
 * `target_type` is the record type key (see {@see \Cms\Core\Records\RecordRegistry}), `target_id` the record key,
 * `mentions` the uuids of the users validly mentioned. Written through {@see \Cms\Core\Records\RecordNoteService}.
 *
 * @property int $id
 * @property string $uuid
 * @property string $target_type
 * @property int $target_id
 * @property string $body
 * @property array<int, string>|null $mentions
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $edited_at
 */
class RecordNote extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = CoreTableConstant::RECORD_NOTES;

    protected $fillable = [
        'uuid',
        'target_type',
        'target_id',
        'body',
        'mentions',
        'user_id',
        'edited_at',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'user_id' => 'integer',
        'mentions' => 'array',
        'edited_at' => 'datetime',
    ];

    /**
     * The user who wrote the note.
     *
     * @return BelongsTo<User, RecordNote>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
