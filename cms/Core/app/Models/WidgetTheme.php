<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Support\Str;
use LogicException;

/**
 * Class WidgetTheme
 * -- About the WidgetTheme Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property integer widget_id
 * @property string name
 * @property array<string, string>|null name_translations  {vi?, en?}; `name` is the fallback
 * @property string content
 * @property string css
 * @property string query
 * @property string project_name
 * @property string created_at
 * @property string updated_at
 *
 * @property-read \Cms\Core\Models\Widget $widget  Parent widget
 */
class WidgetTheme extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(static function (self $theme): void {
            $theme->theme_key ??= 'manual-' . Str::uuid();
        });
        // Only a written value is normalised: a row read without the column must not get it cleared.
        static::saving(static function (self $theme): void {
            if ($theme->isDirty('name_translations')) {
                $theme->name_translations = WidgetNameTranslations::normalize($theme->name_translations);
            }
        });
        static::updating(static function (self $theme): void {
            if ($theme->isDirty('theme_key')) {
                throw new LogicException('Theme key is immutable.');
            }
        });
    }

    /** @var string */
    public $table = CoreTableConstant::WIDGET_THEME;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'widget_id', 'name', 'name_translations', 'content', 'css', 'query', 'project_name',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'widget_id' => 'integer',
        'query' => 'json',
        'project_name' => 'json',
        'name_translations' => 'json:unicode',
    ];

    /**
     * Name in `$locale` (default: the admin language, see WidgetNameTranslations::displayLocale()), else English, else `name`.
     */
    public function localizedName(?string $locale = null): string
    {
        return WidgetNameTranslations::resolve($this->name_translations, (string) $this->name, $locale);
    }

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

    /**
     * Whether a design with this raw `project_name` can be used on `$project`: its list names that project or Default.
     * No project (null or an empty string), and an empty-string entry, count as Default, like the artifact fan-out
     * (an empty-string project is generated into the Default set). An explicit empty list is usable nowhere: the fan-out
     * generates no file for it.
     *
     * One rule for pages and shared areas. {@see self::scopeUsableOnSite()} is the same rule
     * in SQL, and the Widget module's placement checks call {@see self::isUsableOnProject()}.
     */
    public static function projectListAllows(mixed $projectName, string $project): bool
    {
        if (!is_array($projectName)) {
            return empty($projectName) || in_array((string) $projectName, [$project, 'Default'], true);
        }

        foreach ($projectName as $name) {
            if (in_array((string) $name, [$project, 'Default', ''], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this design can be used on `$project` ({@see self::projectListAllows()}).
     */
    public function isUsableOnProject(string $project): bool
    {
        return self::projectListAllows($this->project_name, $project);
    }

    /**
     * Designs this site (`ispa-cms.folder_name`) can use, on pages and in shared areas alike: {@see self::projectListAllows()}
     * in SQL. The page render path reads the site's generated set and then the Default set
     * ({@see \App\Services\WidgetTemplatePathResolver}); a Site Layout placement accepts the same designs. Applies on
     * every environment: nothing exempts local.
     *
     * @param bool $placement Kept for callers that ask for shared-area designs (`placeable=1`); it no longer narrows
     *                        the list, as areas accept every design pages accept.
     */
    public function scopeUsableOnSite(Builder $query, bool $placement = false): Builder
    {
        $site = (string) (config('ispa-cms.folder_name') ?: 'Default');

        return $query->where(function (Builder $q) use ($site): void {
            $column = $q->qualifyColumn('project_name');
            // No project, also when stored as an empty JSON string or an empty-string entry (legacy rows): Default.
            // The JSON test works on every database driver in use.
            $q->whereNull($column)->orWhereJsonContains($column, '');
            foreach (array_unique([$site, 'Default']) as $project) {
                $q->orWhereJsonContains($column, $project);
            }
        });
    }
}
