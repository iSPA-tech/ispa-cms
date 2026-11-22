<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Models;


use App\Models\User;
use Cms\Core\Traits\HasUuid;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Models\Upload;
use Cms\Core\Models\Language;
use Cms\Core\Models\Enumerate;
use Cms\Content\Observers\ContentObserver;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Content
 * About the Content Entity
 *
 * @package Cms\Content\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string uuid
 * @property string name
 * @property string description
 * @property string content
 * @property string video_url
 * @property string target_type
 * @property int target_id
 * @property string published_at
 * @property int language_id
 * @property int user_id
 * @property string status
 * @property bool is_confirmed
 * @property string custom
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read User author - BelongsTo - Get author
 * @property-read Upload[] audios - MorphMany - Get all audio uploaded file
 * @property-read Language language - BelongsTo - Get language
 *
 * @api Content public API; signature changes follow the module notes.
 */
class Content extends Model
{
    use HasFactory, SoftDeletes;
    use SerializeDateTrait;
    use HasUuid, ModelBoot;

    /** @var string */
    public $table = Tables::CONTENTS;

    /** @var string */
    private static string $observer = ContentObserver::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', 'name', 'description', 'content', 'video_url', 'target_type',
        'target_id', 'published_at', 'language_id', 'user_id', 'status', 'is_confirmed', 'custom',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'content' => 'json',
        'target_id' => 'integer',
        'language_id' => 'integer',
        'user_id' => 'integer',
        'is_confirmed' => 'boolean',
        'custom' => 'json',
    ];

    /**
     * Get others languages of this content
     *
     * @return Collection
     * @author malayvuong
     * @since 6.62.6 - 2023-07-01, 20:35 ICT
     */
    public function contents(): Collection
    {
        return (new self())->where('language_id', '!=', $this->language_id)
            ->where('target_type', $this->target_type)
            ->where('target_id', $this->target_id)
            ->with([
                'language:id,name,flag,path',
                'contentStatus:name,name_alias,enum_key',
            ])
            ->get();
    }

    /**
     * Enumerate relationship
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-06-19, 09:31 ICT
     */
    public function contentStatus()
    {
        return $this->belongsTo(Enumerate::class, 'status', 'enum_key');
    }

    /**
     * Author relationship
     *
     * @return BelongsTo
     * @author vuongnm
     * @since 6.62.6 - 2023-01-31, 11:43 ICT
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Target relationship
     *
     * @return MorphTo
     * @author malayvuong
     * @since 1.0.0 - 2022-01-22, 10:52
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * Language relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 1.0.0 - 2022-01-23, 13:29
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Audio relationships
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 1.0.0 - 2022-01-22, 18:3
     */
    public function audios(): BelongsToMany
    {
        return $this->belongsToMany(Upload::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'target_id', 'upload_id')
            ->wherePivot('target_type', get_class($this))
            ->wherePivot('category', 'audios')
            ->orderByPivot('main', 'desc')
            ->orderByPivot('order');
    }

    /**
     * sync images to relationship
     *
     * @param array $data
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2023-05-03, 09:41 ICT
     */
    public function syncAudios(array $data = []): void
    {
        if (!empty($data)) {
            $imageData = [];
            foreach ($data as $index => $image) {
                $imageData[$image['id']] = [
                    'target_type' => get_class($this),
                    'order' => $index,
                    'category' => 'audios',
                    'main' => $index === 0,
                ];
            }

            $this->audios()->sync($imageData);
        }
    }
}
