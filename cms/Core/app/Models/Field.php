<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Traits\ModelBoot;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Observers\FieldObserver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Field
 * About the FieldGroup Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 * @last_updated 6.62.6 - 2023-07-21, 14:22 ICT - by malayvuong
 *
 * @property int id
 * @property string form_id
 * @property string name
 * @property string custom
 * @property string styles
 * @property bool status
 * @property string expires_from
 * @property string expires_to
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\FieldItem[] $items  Field items ordered by priority
 */
class Field extends Model
{
    use HasFactory, SoftDeletes;
    use ModelBoot;

    /** @var string */
    public $table = CoreTableConstant::FIELDS;

    /** @var string */
    private static string $observer = FieldObserver::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'form_id', 'name', 'custom', 'styles', 'status', 'expires_from', 'expires_to',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'custom' => 'json',
        'expires_from' => 'datetime',
        'expires_to' => 'datetime',
    ];

    /**
     * Field items relationship
     *
     * @return HasMany
     *
     * @author malayvuong
     * @since 1.0.0 - 12/2/21 12:31
     */
    public function items(): HasMany
    {
        return $this->hasMany(FieldItem::class, 'field_id')->orderBy('priority', 'ASC');
    }
}
