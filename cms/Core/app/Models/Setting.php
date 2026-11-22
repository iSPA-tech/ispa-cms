<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Eloquent;
use App\Casts\EncryptedSettingValue;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Setting
 * About the Setting Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int $id
 * @property string $setting_key
 * @property string $type
 * @property string $group_name
 * @property string $name
 * @property string $value
 * @property int $enumerate_id
 * @property int $language_id
 * @property array|null $input_setting
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * ==== Relationships
 * @property-read Language $language
 *
 * @mixin Eloquent
 */
class Setting extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::SETTINGS;

    /** @var array $defaultInputSetting
     *
     * required: false,
     * multiple: false,
     * min: -1,
     * max: -1,
     * language: false,
     * title: null,
     * description: null,
     * rows: 3,
     * editor: null,
     * options: [
     * //  {value: xxx, label: xxx}
     * ],
     * parent_group: null
     * isEnv: false
     * isPublic: true
     */
    public array $defaultInputSetting = [
        'required' => false,
        'multiple' => false,
        'min' => -1,
        'max' => -1,
        'language' => false,
        'title' => null,
        'description' => null,
        'rows' => 3,
        'editor' => null,
        'options' => [],
        'parent_group' => null,
        'isEnv' => false,
        'isPublic' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'setting_key', 'type', 'group_name', 'name', 'value', 'enumerate_id', 'language_id', 'input_setting',
    ];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'enumerate_id' => 'integer',
        'language_id' => 'integer',
        'input_setting' => 'array',
        'value' => EncryptedSettingValue::class,
    ];

    /**
     * Ensure type and input_setting are filled before value so that the
     * EncryptedSettingValue cast can read them when deciding whether to encrypt.
     * @param  array $attributes
     * @return self
     */
    public function fill(array $attributes): self
    {
        $priority = [];
        foreach (['type', 'input_setting'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $priority[$key] = $attributes[$key];
                unset($attributes[$key]);
            }
        }

        return parent::fill(array_merge($priority, $attributes));
    }

    /**
     * Language relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-19, 1:27
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
