<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Models\Language;
use App\Observers\CalendarObserver;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Cms\Core\Traits\Models\StatusScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Calendar
 * About the Calendar Entity
 *
 * @package App\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property integer id
 * @property string name
 * @property string date_from
 * @property string date_to
 * @property string url
 * @property boolean is_all_day
 * @property boolean is_repeat
 * @property integer repeat_type 0: yearly, 1: monthly
 * @property integer language_id
 * @property integer user_id
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 *
 */
class Calendar extends Model
{
    use HasFactory, SoftDeletes;
    use SerializeDateTrait, ModelBoot, StatusScope;

    /** @var string */
    public $table = Tables::CALENDARS;

    /** @var string */
    private static string $observer = CalendarObserver::class;

    /** @var string[] */
    protected $dates = [
        'created_at', 'updated_at', 'deleted_at', 'date_from', 'date_to',
    ];

    protected $appends = [
        'date', 'format_date_to', 'format_date_from',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'date_from', 'date_to', 'url', 'is_all_day', 'is_repeat', 'repeat_type', 'status', 'language_id', 'user_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_all_day' => 'boolean',
        'is_repeat' => 'boolean',
        'repeat_type' => 'integer',
        'language_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Return date from after set timezone
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2022-08-15, 02:17 ICT
     */
    public function formatDateFrom(): Attribute
    {
        return new Attribute(
            get: fn() => now()->parse($this->date_from)->setTimezone(get_timezone())->format('Y-m-d H:i:s')
        );
    }

    /**
     * Return date to after set timezone
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2022-08-15, 02:17 ICT
     */
    public function formatDateTo(): Attribute
    {
        return new Attribute(
            get: fn() => now()->parse($this->date_to)->setTimezone(get_timezone())->format('Y-m-d H:i:s')
        );
    }

    /**
     * Language relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-17, 23:12
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Return slug from relationship
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-05-01, 11:26 ICT
     */
    public function date(): Attribute
    {
        return new Attribute(
            get: fn() => now()->parse($this->date_from)->setTimezone(get_timezone())->format('d/m')
        );
    }
}
