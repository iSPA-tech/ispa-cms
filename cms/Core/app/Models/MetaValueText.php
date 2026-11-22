<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MetaValueText
 * About the MetaValueText Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int id
 * @property string value
 * @property string label
 * @property int user_id
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @mixin \Eloquent
 */
class MetaValueText extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::META_VALUES_TEXT;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'value', 'value_hash', 'label', 'user_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
    ];

    /**
     * Keep value_hash in sync: LONGTEXT cannot carry a unique index, so the
     * hash column enforces value-pool uniqueness for text values.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving(static function (self $model): void {
            $model->value_hash = $model->value === null ? null : sha1((string) $model->value);
        });
    }
}
