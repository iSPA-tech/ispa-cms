<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Intervention\Image\Drivers\Imagick\Driver;

class RequestCropImageJob implements ShouldQueue
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $disk,
        public string $sourcePath,
        public string $outPutPath,
        public int    $width = 0,
        public int    $height = 0,
        public string $extension = 'webp',
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if (Storage::disk($this->disk)->exists($this->outPutPath)) {
                return;
            }

            //  Check if original image exists in storage -> return original image path
            if (Storage::disk($this->disk)->exists($this->sourcePath)) {
                $filePath = Storage::disk($this->disk)->path($this->sourcePath);
            } else {
                //  Else return default image path
                $filePath = public_path('images/default.jpeg');
            }
            ini_set('memory_limit', '256M');
            //  Process to crop image here
            $manager = new ImageManager(new Driver());
            $image = $manager->read($filePath);
            // If no height => resize by width
            if (empty($this->height) || $this->height < 50) {
                $image = $image->scale($this->width);
            }
            //  Crop image with width and height
            if ($this->width > 50 && $this->height > 50) {
                $image = $image->cover($this->width, $this->height);
            }

            //  Same treatment the variant service gives its derivatives: a
            //  source photo's EXIF/XMP/8BIM can outweigh the pixels of a small
            //  crop several times over. Non-sRGB ICC profiles are kept, so
            //  wide-gamut images do not come out flat.
            app(\Cms\Core\Services\ImageVariantService::class)->stripMetadata($image);

            if (in_array($this->extension, ['jpg', 'jpeg'])) {
                $encoded = $image->toJpeg(($this->width <= 300 || $this->height <= 300) ? 50 : 75);
            } else if ($this->extension === 'png') {
                $encoded = $image->toPng();
            } else if ($this->extension === 'gif') {
                $encoded = $image->toGif();
            } else {
                $encoded = $image->toWebp(($this->width <= 300 || $this->height <= 300) ? 50 : 75);
            }

            //  Save image to destination
            Storage::disk($this->disk)->put($this->outPutPath, $encoded);
        } catch (Exception $e) {
            Log::debug($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
