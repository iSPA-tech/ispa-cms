<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Support\Str;
use LogicException;

/**
 * Class Widget
 * -- About the Widget Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property string name
 * @property string description
 * @property array<string, string>|null name_translations  {vi?, en?}; `name` is the fallback
 * @property array<string, string>|null description_translations  {vi?, en?}; `description` is the fallback
 * @property string js
 * @property string origin  Widget::ORIGIN_HUB or Widget::ORIGIN_SITE
 * @property string|null publisher
 * @property string|null cloned_from_widget_key
 * @property \Illuminate\Support\Carbon|null cloned_at
 * @property \Illuminate\Support\Carbon|null blocked_at
 * @property int|null blocked_by
 * @property string|null blocked_reason
 * @property string created_at
 * @property string updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\WidgetTheme[] $template  Widget theme templates
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\WidgetInput[] $inputs  Widget input definitions
 */
class Widget extends Model
{
    use HasFactory;

    /** Widget shipped in a hub catalog (Core seed or a hub publisher). */
    public const ORIGIN_HUB = 'hub';

    /** Widget created on this site. */
    public const ORIGIN_SITE = 'site';

    protected static function booted(): void
    {
        static::creating(static function (self $widget): void {
            $widget->widget_key ??= 'manual.' . Str::uuid();
        });
        // Only a written value is normalised: a row read without the column must not get it cleared.
        static::saving(static function (self $widget): void {
            if ($widget->isDirty('name_translations')) {
                $widget->name_translations = WidgetNameTranslations::normalize($widget->name_translations);
            }
            if ($widget->isDirty('description_translations')) {
                $widget->description_translations = WidgetNameTranslations::normalize($widget->description_translations);
            }
        });
        static::updating(static function (self $widget): void {
            if ($widget->isDirty('widget_key')) {
                throw new LogicException('Widget key is immutable.');
            }
        });
    }

    /** @var string */
    public $table = CoreTableConstant::WIDGET;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'description', 'js', 'name_translations', 'description_translations',
        'origin', 'publisher', 'cloned_from_widget_key', 'cloned_at',
        'blocked_at', 'blocked_by', 'blocked_reason',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'cloned_at' => 'datetime',
        'blocked_at' => 'datetime',
        'blocked_by' => 'integer',
        'name_translations' => 'json:unicode',
        'description_translations' => 'json:unicode',
    ];

    /**
     * Name in `$locale` (default: the admin language, see WidgetNameTranslations::displayLocale()), else English, else `name`.
     */
    public function localizedName(?string $locale = null): string
    {
        return WidgetNameTranslations::resolve($this->name_translations, (string) $this->name, $locale);
    }

    /**
     * Description in `$locale` (default: the admin language, see WidgetNameTranslations::displayLocale()), else English, else `description` (may be null).
     */
    public function localizedDescription(?string $locale = null): ?string
    {
        if ($this->description_translations === null && $this->description === null) {
            return null;
        }

        return WidgetNameTranslations::resolve($this->description_translations, $this->description, $locale);
    }

    public function isHub(): bool
    {
        return $this->origin === self::ORIGIN_HUB;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Template relationship
     *
     * @return HasMany
     * @author malayvuong
     * @since 7.160.1175 - 2025-03-29, 17:07 ICT
     */
    public function template(): HasMany
    {
        return $this->hasMany(WidgetTheme::class);
    }

    /**
     * Inputs relationship
     *
     * @return HasMany
     * @author malayvuong
     * @since 7.160.1175 - 2025-03-29, 17:09 ICT
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(WidgetInput::class);
    }
}
