<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Support\Str;
use LogicException;

/**
 * Class WidgetInput
 * -- About the WidgetInput Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property integer widget_id
 * @property string name
 * @property string type
 * @property string default_value
 * @property string options
 * @property string created_at
 * @property string updated_at
 *
 * @property-read \Cms\Core\Models\Widget $widget  Parent widget
 */
class WidgetInput extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(static function (self $input): void {
            $input->input_key ??= 'manual-' . Str::uuid();
        });
        static::updating(static function (self $input): void {
            if ($input->isDirty('input_key')) {
                throw new LogicException('Input key is immutable.');
            }
        });
    }

    /** @var string */
    public $table = CoreTableConstant::WIDGET_INPUT;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'widget_id', 'name', 'type', 'default_value', 'options',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'widget_id' => 'integer',
        'options' => 'json',
    ];

    /**
     * Widget relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 7.160.1175 - 2025-03-29, 17:11 ICT
     */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }
}
