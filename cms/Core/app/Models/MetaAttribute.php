<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Traits\HasUuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MetaAttribute
 * About the MetaAttribute Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string name
 * @property string label
 * @property string type
 * @property string description
 * @property string input_type
 * @property string|null unit  Canonical measure unit for numeric attributes (kW, Nm, mm, ...)
 * @property string|null display_unit  Reader-facing unit for the render projection
 * @property string|null display_multiplier  Canonical-to-display numeric multiplier
 * @property bool is_filter
 * @property bool is_enabled
 * @property bool is_required
 * @property bool is_multiple
 * @property string|null uuid  Stable public identifier
 * @property array|null labels  Custom field labels per locale: {vi, en}
 * @property array|null help  Custom field help text per locale: {vi, en}
 * @property array|null rules  Custom field validation rules
 * @property bool is_custom  True for operator-defined custom fields
 * @property bool show_in_list  Show the custom field as a list column
 * @property bool is_searchable  Include the custom field in list search
 * @property \Illuminate\Support\Carbon|null archived_at  When the custom field was archived
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueBigNum[] $bigNumValues  Big integer value options
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueTinyNum[] $tinyNumValues  Small integer value options
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueString[] $stringValues  String value options
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueText[] $textValues  Text value options
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueDate[] $dateValues  Date value options
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaValueBoolean[] $booleanValues  Boolean value options
 *
 * @mixin \Eloquent
 */
class MetaAttribute extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::META_ATTRIBUTES;

    /** @var string[] $appends */
    protected $appends = [
        'total_values',
        'translated_label',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'label', 'type', 'description', 'input_type', 'unit', 'is_filter', 'is_enabled', 'is_required', 'is_multiple',
        'parent_id', 'display_tier', 'priority', 'compose_rule', 'value_kind', 'display_precision', 'display_unit',
        'display_multiplier', 'hide_zero',
        'uuid', 'labels', 'help', 'rules', 'is_custom', 'show_in_list', 'is_searchable', 'archived_at',
    ];

    /** Value shapes the spec pipeline knows how to canonicalise. */
    public const KIND_MEASURE = 'measure';
    public const KIND_BOOL = 'bool';
    public const KIND_ENUM = 'enum';
    public const KIND_TEXT = 'text';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_filter' => 'boolean',
        'is_enabled' => 'boolean',
        'is_required' => 'boolean',
        'is_multiple' => 'boolean',
        'display_tier' => 'integer',
        'priority' => 'integer',
        'display_precision' => 'integer',
        'display_multiplier' => 'decimal:10',
        'hide_zero' => 'boolean',
        'labels' => 'array',
        'help' => 'array',
        'rules' => 'array',
        'is_custom' => 'boolean',
        'show_in_list' => 'boolean',
        'is_searchable' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Limit the query to operator-defined custom fields.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_custom'), true);
    }

    /**
     * Limit the query to custom fields attached to the meta class that holds
     * the custom fields of one admin resource (meta_classes.resource_key).
     *
     * @param Builder $query
     * @param string  $resourceKey
     * @return Builder
     */
    public function scopeForResource(Builder $query, string $resourceKey): Builder
    {
        $pivot = CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT;
        $classes = CoreTableConstant::META_CLASSES;

        return $query->custom()->whereExists(function ($exists) use ($pivot, $classes, $resourceKey): void {
            $exists->selectRaw('1')
                ->from($pivot)
                ->join($classes, "{$classes}.id", '=', "{$pivot}.meta_class_id")
                ->whereColumn("{$pivot}.meta_attribute_id", $this->qualifyColumn('id'))
                ->where("{$classes}.resource_key", $resourceKey)
                ->whereNull("{$classes}.deleted_at");
        });
    }

    /**
     * Get translated label attribute
     *
     * @return Attribute
     * @author vuongnm
     * @since 7.160.1175 - 2023-09-25, 21:14 ICT
     */
    public function translatedLabel(): Attribute
    {
        return new Attribute(
            get: fn() => __($this->label),
        );
    }

    /**
     * Count total value of this attribute
     *
     * @return integer
     * @author malayvuong
     * @since 6.62.6 - 2022-11-19, 07:27 ICT
     */
    public function getTotalValuesAttribute()
    {
        //  return total count in pivot table meta_attribute_value_pivot by DB::table
        return DB::table(CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT)->select('meta_attribute_id')->where('meta_attribute_id', $this->id)->count();
        //  Todo: Check this feature => Return total count in pivot table by "with count" method
        //  return $this->belongsToMany(MetaValue::class, Tables::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')->count();
    }

    /**
     * Get options relationship by type
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 21:05 ICT
     */
    public function options(): mixed
    {
        if (empty($this->getAttribute('type'))) {
            $this->setAttribute('type', 'string');
        }

        $mappedType = [
            'bigInt' => $this->bigNumValues(),
            'string' => $this->stringValues(),
            'text' => $this->textValues(),
            'tinyInt' => $this->tinyNumValues(),
            'date' => $this->dateValues(),
            'boolean' => $this->booleanValues(),
        ];

        return $mappedType[$this->getAttribute('type')];
    }

    /**
     * Big Integer Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function bigNumValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueBigNum::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueBigNum::class);
    }

    /**
     * Small Integer Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function tinyNumValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueTinyNum::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueTinyNum::class);
    }

    /**
     * String Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function stringValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueString::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueString::class);
    }

    /**
     * Text Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function textValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueText::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueText::class);
    }

    /**
     * Date Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function dateValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueDate::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueDate::class);
    }

    /**
     * Boolean Values options relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-18, 20:46 ICT
     */
    public function booleanValues(): BelongsToMany
    {
        return $this->belongsToMany(MetaValueBoolean::class, CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, 'meta_attribute_id', 'value_id')
            ->where('value_type', MetaValueBoolean::class);
    }
}
