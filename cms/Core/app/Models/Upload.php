<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Eloquent;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Core\Traits\ModelBoot;
use Cms\Content\Models\Content;
use Cms\Product\Models\Product;
use Cms\Core\Observers\UploadObserver;
use Cms\Core\Services\ImageVariantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class Upload
 * About the Upload Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 * @last_updated 6.62.6 - 2023-02-18, 22:42 ICT
 *
 * @property int $id
 * @property string $disk
 * @property string $type
 * @property string $mime_type
 * @property string $path
 * @property string $name
 * @property string $name_alias
 * @property string $alt_text
 * @property int $file_size
 * @property bool $main
 * @property array|null $exif
 * @property string|null $target_folder
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $category
 * @property array|null $dimensions
 * @property int|null $user_id
 * @property array|null $custom
 * @property array|null $config
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * ==== Appended / Accessors
 * @property-read string $url
 * @property-read string $size
 * @property-read string $thumbnail_sm
 * @property-read string $thumbnail_md
 * @property-read string $thumbnail_lg
 * @property-read string $thumbnail_xl
 * @property-read string $cover_sm
 * @property-read string $cover_md
 * @property-read string $cover_lg
 * @property-read string $cover_xl
 * @property-read string $sm
 * @property-read string $md
 * @property-read string $lg
 * @property-read string $xl
 * @property-read string $facebook
 * @property-read string $twitter
 *
 * ==== Relationships
 * @property-read MorphTo $target
 *
 * @mixin Eloquent
 */
class Upload extends Model
{
    use HasFactory, SoftDeletes;
    use SerializeDateTrait;
    use ModelBoot;

    /** @var string */
    private static string $observer = UploadObserver::class;
    /** @var string */
    public $table = CoreTableConstant::UPLOADS;

    //  Append attribute to called when return data
    protected $appends = [
        'url', 'size',
    ];

    //  After loaded model, upon the model's is image or file, we will set the `appends` attribute value
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'disk', 'type', 'mime_type', 'path', 'name', 'name_alias', 'alt_text', 'file_size',
        'main', 'exif', 'target_folder', 'target_type', 'target_id', 'category',
        'dimensions', 'user_id', 'custom', 'config',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'main' => 'boolean',
        'exif' => 'json',
        'dimensions' => 'json',
        'custom' => 'json',
        'config' => 'json',
        'target_id' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::retrieved(static function (Upload $upload) {
            if (!in_array($upload->type, ['image', 'avatar'])) {
                return;
            }

            $appends = [
                ...$upload->getAppends(),
                'thumbnail_sm',
                'cover_sm',
                'sm',
                'cover_md',
                'cover_lg',
                'cover_xl',
            ];

            if ($upload->target_folder === 'Banner') {
                $appends = [...$appends, 'md', 'lg', 'xl'];
            }

            if ($upload->isRealEstatePropertyAsset()) {
                $appends = [
                    ...$appends,
                    'thumbnail_md',
                    'thumbnail_lg',
                    'thumbnail_xl',
                    'md',
                    'lg',
                    'xl',
                ];
            }

            $upload->setAppends(array_values(array_unique($appends)));
        });
    }

    /**
     * Pivot relationship
     *
     * @return HasMany
     * @author malayvuong
     * @since 6.62.6 - 2023-05-07, 21:52 ICT
     */
    public function pivots(): HasMany
    {
        return $this->hasMany(UploadTarget::class, 'upload_id', 'id');
    }

    /**
     * Get the User target that owns the Upload through pivot table
     *
     * @return BelongsToMany
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 15:48 ICT
     */
    public function targetUser(): BelongsToMany
    {
        return $this->belongsToMany(User::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'upload_id', 'target_id')
            ->where('target_type', User::class)
            ->withPivot('target_type', 'category', 'main');
    }

    /**
     * Get the Product target that owns the Upload through pivot table
     *
     * @return BelongsToMany
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 15:48 ICT
     */
    public function targetProduct(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'upload_id', 'target_id')
            ->where('target_type', Product::class)
            ->withPivot('target_type', 'category', 'main');
    }

    /**
     * Get the Post target that owns the Upload through pivot table
     *
     * @return BelongsToMany
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 15:48 ICT
     */
    public function targetPost(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'upload_id', 'target_id')
            ->where('target_type', Post::class)
            ->withPivot('target_type', 'category', 'main');
    }

    /**
     * Get the Category target that owns the Upload through pivot table
     *
     * @return BelongsToMany
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 15:48 ICT
     */
    public function targetCategory(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'upload_id', 'target_id')
            ->where('target_type', Category::class)
            ->withPivot('target_type', 'category', 'main');
    }

    /**
     * Determine if upload belongs to Real Estate property/gallery to append more sizes.
     */
    protected function isRealEstatePropertyAsset(): bool
    {
        $targetType = $this->target_type ?? '';
        $folder = $this->target_folder ?? '';

        if (str_contains($targetType, 'RealEstateProperty')) {
            return true;
        }

        return in_array($folder, ['RealEstateProperty', 'RealEstateProject'], true);
    }

    /**
     * Increase view|downloaded count of upload
     *
     * @param string $key
     *
     * @return bool|int
     * @author malayvuong
     * @since 6.47.5 - 2022-08-12, 00:23 ICT
     */
    public function increaseCustomKey(string $key = 'view'): bool|int
    {
        $currentView = (new self())->query()->select('id', 'custom')->find($this->id);

        if (!$currentView) {
            return false;
        }
        // CPL: create new custom value equal current custom, them plus 1 into new custom $key
        // after that set it to current custom, at last save it to database and return
        $newCustom = $currentView->custom;
        //  Check if custom key is exist and increase value
        if (isset($newCustom[$key])) {
            $newCustom[$key]++;
        } else {
            $newCustom[$key] = 1;
        }
        $currentView->custom = $newCustom;

        return $currentView->save();
    }

    /**
     * Get category relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.47.5 - 2022-08-08, 17:29 ICT
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_target', 'target_id', 'category_id')->where('target_type', __CLASS__);
    }

    /**
     * Get category target
     *
     * @return HasMany
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 16:48 ICT
     */
    public function categoryTarget(): HasMany
    {
        return $this->hasMany(CategoryTarget::class, 'target_id')->where('target_type', __CLASS__);
    }

    /**
     * One content relationship
     *
     * @return MorphOne
     * @author malayvuong
     * @since 6.47.5 - 2022-08-10, 01:08 ICT
     */
    public function content(): MorphOne
    {
        return $this->morphOne(Content::class, 'target')->where('language_id', lang()->id ?? 1);
    }

    /**
     * Provinces relationship
     *
     * @return MorphMany
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function contents(): morphMany
    {
        return $this->morphMany(Content::class, 'target');
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author phamanhphien
     *
     * @since 1.0.0
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * File size formatted
     *
     * @return Attribute
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-10, 14:13
     */
    public function size(): Attribute
    {
        return new Attribute(
            get: fn() => file_size_format($this->file_size)
        );
    }

    /**
     * Return small scale of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function sm(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('sm')
        );
    }

    /**
     * Process to crop image and return path of image
     *
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 14:22 ICT
     */
    public function cropImage(string $type): string
    {
        return app(ImageVariantService::class)->url($this, $type);
    }

    public function ensureImageVariant(string $type): string
    {
        return app(ImageVariantService::class)->url($this, $type, true);
    }

    /**
     * Return medium scale of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function md(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('md')
        );
    }

    /**
     * Return twitter size of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function twitter(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('twitter')
        );
    }

    /**
     * Return facebook size of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function facebook(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('facebook')
        );
    }

    /**
     * Return scale of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function lg(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('lg')
        );
    }

    /**
     * Return large scale of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function xl(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('xl')
        );
    }

    /**
     * Return small thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function thumbnailSm(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('thumbnail_sm')
        );
    }

    /**
     * Return medium thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function thumbnailMd(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('thumbnail_md')
        );
    }

    /**
     * Return default thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function thumbnailLg(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('thumbnail_lg')
        );
    }

    /**
     * Return large thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function thumbnailXl(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('thumbnail_xl')
        );
    }

    /**
     * Return small thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function coverSm(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('cover_sm')
        );
    }

    /**
     * Return medium thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function coverMd(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('cover_md')
        );
    }

    /**
     * Return default thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function coverLg(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('cover_lg')
        );
    }

    /**
     * Return large thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 13:38 ICT
     */
    public function coverXl(): Attribute
    {
        return new Attribute(
            get: fn() => $this->cropImage('cover_xl')
        );
    }

    /**
     * Return url of image/file
     *
     * @return string
     * @author malayvuong
     * @since 1.0.0 - 2022-02-10, 14:13
     */
    public function getUrlAttribute(): string
    {
        return $this->publicUrlFor($this->getTargetFile());
    }

    /**
     * True once every tracked R2 object of this upload is synced AND a public
     * CDN base URL is configured — the switch that flips URL building from
     * local /storage to the CDN host.
     */
    public function servedFromR2(): bool
    {
        return $this->getAttribute('r2_synced_at') !== null
            && (string) config('filesystems.disks.r2.url') !== '';
    }

    /**
     * Build the public URL for a storage-relative path (original or variant),
     * honoring the CDN cutover marker. R2 keys mirror the local relative
     * paths one-to-one, so the path never changes — only the base does.
     */
    public function publicUrlFor(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($this->servedFromR2()) {
            return rtrim((string) config('filesystems.disks.r2.url'), '/') . '/' . $relativePath;
        }

        return asset('/storage/' . $relativePath);
    }

    /**
     * Get file from Disk Storage
     *-------------------
     *
     * @return string
     *
     * @author malayvuong
     **/
    public function getTargetFile(): string
    {
        return $this->getAttribute('path') . '/' . $this->getAttribute('name');
    }

    /**
     * Get temporary file from Disk Storage
     * -- This only apply for `aws` disk
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 16:43 ICT
     */
    public function temporaryUrl(): Attribute
    {
        return new Attribute(
            get: fn() => Storage::disk($this->disk)->temporaryUrl($this->getTargetFile(), now()->addMinutes(5))
        );
    }

    /**
     * Morph to target
     *
     * @return MorphTo
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }
}
