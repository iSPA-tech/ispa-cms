<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use App\Observers\LanguageObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Language
 * About the Language Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int $id
 * @property string $name
 * @property string $currency
 * @property string $path
 * @property string $path_alias
 * @property string $code
 * @property string $flag
 * @property string $time_zone
 * @property bool $default_web
 * @property bool $default_adm
 * @property bool $default_app
 * @property bool $for_web
 * @property bool $for_adm
 * @property bool $for_app
 * @property string $status
 * @property bool $is_locked
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * @property-read \Cms\Core\Models\Enumerate $status  Status enumerate relationship
 *
 * @mixin \Eloquent
 */
class Language extends Model
{
    use HasFactory, SoftDeletes;
    use ModelBoot, StatusScope;

    /** @var string */
    private static string $observer = LanguageObserver::class;

    /** @var string */
    public $table = CoreTableConstant::LANGUAGES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'currency', 'path', 'path_alias', 'code', 'flag',
        'time_zone', 'default_app', 'default_web', 'default_adm',
        'for_web', 'for_adm', 'for_app', 'status', 'is_locked',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'default_web' => 'boolean',
        'default_adm' => 'boolean',
        'default_app' => 'boolean',
        'for_web' => 'boolean',
        'for_adm' => 'boolean',
        'for_app' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function getPathAttribute(mixed $value): ?string
    {
        return $this->trimCharValue($value);
    }

    public function getPathAliasAttribute(mixed $value): ?string
    {
        return $this->trimCharValue($value);
    }

    public function getCodeAttribute(mixed $value): ?string
    {
        return $this->trimCharValue($value);
    }

    public function getCurrencyAttribute(mixed $value): ?string
    {
        return $this->trimCharValue($value);
    }

    private function trimCharValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Status relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Enumerate::class, 'status')->where('type', 'status');
    }
}
