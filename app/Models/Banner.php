<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Models\Upload;
use App\Observers\BannerObserver;
use Cms\Core\Models\Language;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Models\AbstractBaseModel;
use Cms\Core\Traits\Models\HasMediaTrait;
use Cms\Core\Traits\Models\ResolvesAppendedRelations;
use Cms\Core\Traits\Models\StatusScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Banner
 * About the Banner Entity
 *
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string name
 * @property string type
 * @property string url
 * @property int click
 * @property int view
 * @property int limit_by
 * @property string limit_from
 * @property string limit_to
 * @property int limit_click
 * @property int limit_view
 * @property string custom
 * @property int language_id
 * @property string status
 * @property int user_id
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read array limit_date
 * @property-read bool is_enabled
 *
 * === Relationships
 * @property User author - BelongsTo - Get Author user created this banner
 * @property Language language - BelongsTo - Get language of this banner
 * @property Upload image - MorphTo - Get Uploaded image
 * @property Upload[] images - MorphMany - Get Uploaded image
 */
class Banner extends AbstractBaseModel
{
    use HasFactory, SoftDeletes;
    use ModelBoot, SerializeDateTrait, HasMediaTrait, StatusScope;
    use ResolvesAppendedRelations;

    /** @var string */
    public $table = Tables::BANNERS;

    /** @var string */
    private static string $observer = BannerObserver::class;

    //  Append attribute to called when return data
    protected $appends = [
        'limit_date',
        'is_enabled',
        'image',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'type', 'url', 'click', 'view', 'limit_by', 'limit_from',
        'limit_to', 'limit_click', 'limit_view', 'custom', 'language_id', 'status', 'user_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'click' => 'integer',
        'view' => 'integer',
        'limit_by' => 'integer',
        'limit_click' => 'integer',
        'limit_view' => 'integer',
        'language_id' => 'integer',
        'user_id' => 'integer',
        'custom' => 'json',
        'limit_from' => 'datetime',
        'limit_to' => 'datetime',
    ];

    /**
     * check is the banner exist with condition limit
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.19.6 - 2022-06-26, 11:19 ICT
     */
    public function isEnabled(): Attribute
    {
        return new Attribute(
            get: fn() => $this->limit_by === 0
                || ($this->limit_by === 1 && now()->parse($this->limit_from) < now() && now()->parse($this->limit_to) > now())
                || ($this->limit_by === 2 && $this->limit_click > $this->click)
                || ($this->limit_by === 3 && $this->limit_view > $this->view)
        );
    }

    /**
     * Language relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.3.8 - 2022-02-23, 0:17
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    /**
     * Set limit_date data
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-02-14, 12:4
     */
    public function limitDate(): Attribute
    {
        return new Attribute(get: fn() => [$this->limit_from, $this->limit_to]);
    }

    /**
     * Author relationship
     *
     * @return belongsTo
     * @author malayvuong
     * @since 1.0.0 - 2022-02-14, 8:54
     */
    public function author(): belongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
