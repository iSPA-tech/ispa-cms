<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Tinify\Tinify;
use function Tinify\fromFile;
use function Tinify\getCompressionCount;

class OptimizedImageService
{
    /** @var bool */
    public bool $isDiscovery = false;

    protected string $key;

    /**
     * Optimized constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-30, 12:04 ICT
     */
    public function __construct()
    {
        $this->key = config('services.tinify.key');
        Tinify::setKey($this->key);
    }

    /**
     * Process to optimized image
     *
     * @param
     * @param string $path
     * @param string $fileName
     * @param string $type
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-30, 12:23 ICT
     */
    public function optimize($source, string $path, string $fileName, string $type = '.jpg'): void
    {
        $mainPath = storage_path('app/public/'. $path . $fileName);
        $fromSource = fromFile($source)->convert(['type' => 'image/' . str($type)->replace('.', '')]);

        //  Save the compressed image to a file
        $fromSource->toFile($mainPath . $type);
    }

    /**
     * Get optimized quota limit
     *
     * @return int
     * @author malayvuong
     * @since 6.62.6 - 2022-10-30, 16:30 ICT
     */
    public function count(): int
    {
        return (getCompressionCount() ?? 500);
    }

    /**
     * Other methods to optimized
     *
     * @param $fromSource
     * @param $mainPath
     * @param $type
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-30, 13:30 ICT
     */
    public function other($fromSource, $mainPath, $type): void
    {
        //  Thumbnail square medium
        $fromSource->resize([
            "method" => "thumb",
            "width" => 300,
            "height" => 300
        ])->toFile($mainPath . '-thumb-300-' . '.' . $type);
        //  Cover image
        $fromSource->resize([
            "method" => "cover",
            "width" => 300,
            "height" => 180
        ])->toFile($mainPath . 'cover-300-' . '.' . $type);
        //  Social cover
        $fromSource->resize([
            "method" => "cover",
            "width" => 600,
            "height" => 335
        ])->toFile($mainPath . 'social-' . '.' . $type);
    }
}
