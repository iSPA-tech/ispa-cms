<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MetaData
 * About the MetaData Entity
 *
 * @package Cms\Core\Models
 * @author  malayvuong
 * @since   1.0.0
 *
 * @property int    $id
 * @property string $target_type
 * @property int    $target_id
 * @property int    $meta_class_id
 * @property int    $meta_attribute_id
 * @property string $value_type
 * @property int    $value_id
 * @property string $created_at
 * @property string $updated_at
 *
 * ==== Relationships
 * @property-read \Illuminate\\Database\\Eloquent\\Model|\Eloquent $target
 * @property-read MetaClass $class
 * @property-read MetaAttribute $attribute
 * @property-read \Illuminate\\Database\\Eloquent\\Model|\Eloquent $value
 *
 * @mixin \Eloquent
 */
class MetaData extends Model
{
    use HasFactory;

    /** @var string */
    public $table = CoreTableConstant::META_DATA;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'target_type', 'target_id', 'meta_class_id', 'meta_attribute_id', 'value_type', 'value_id', 'source',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'target_id' => 'integer',
        'meta_class_id' => 'integer',
        'meta_attribute_id' => 'integer',
        'value_id' => 'integer',
    ];

    /**
     * Morph to target
     *
     * @return MorphTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * Meta Class relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.62.6 - 2022-11-13, 20:49 ICT
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(MetaClass::class, 'meta_class_id', 'id');
    }

    /**
     * Meta Attribute relationships
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.62.6 - 2022-11-24, 12:00 ICT
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(MetaAttribute::class, 'meta_attribute_id', 'id');
    }

    /**
     * Meta Attribute relationships
     *
     * @return MorphTo
     * @author malayvuong
     * @since 6.62.6 - 2022-11-24, 12:00 ICT
     */
    public function value(): MorphTo
    {
        return $this->morphTo('value');
    }
}
