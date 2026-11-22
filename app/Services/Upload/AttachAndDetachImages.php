<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services\Upload;

use DOMDocument;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Models\UploadTarget;
use Cms\Core\Services\UploadReferenceGuard;
use Illuminate\Support\Facades\Facade;

class AttachAndDetachImages extends Facade
{
    /** @var bool */
    public bool $isDiscovery = false;

    /**
     * Attach images to contents
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-12-04, 23:32 ICT
     */
    public static function attachImageInsideContent(mixed $model = null): void
    {
        //  Check if model is null or not exits ['content'] or ['content'] is not an array
        if (!$model || !isset($model->content) || !is_array($model->content)) {
            return;
        }

        DB::beginTransaction();
        try {

            //  Get all image id
            $imageIds = self::getImageIds($model->content);

            $insertData = [];
            //  Attach all images by insert into UploadTarget
            foreach ($imageIds as $index => $imageId) {
                //  Check if imageId is null or lower than 1
                if (!$imageId || $imageId < 1) {
                    continue;
                }

                $insertData[] = [
                    'upload_id' => $imageId,
                    'target_id' => $model->id,
                    'target_type' => get_class($model),
                    'category' => 'content',
                    'order' => $index,
                ];
            }
            //  Insert all data
            if (config('ispa-cms.upload.lock_references') === true) {
                app(UploadReferenceGuard::class)->lockActive($imageIds);
            }
            UploadTarget::query()->insert($insertData);
        } catch (Exception $e) {
            Log::error('Cannot Attach images inside content', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            DB::rollBack();
            if (config('ispa-cms.upload.lock_references') === true) {
                throw $e;
            }
            return;
        }

        DB::commit();
    }

    protected static function getImageIds(array $content = []): array
    {
        $imageIds = [];

        collect($content)->each(static function ($item) use (&$imageIds) {
            $type = $item['type'] ?? null;
            $value = $item['value'] ?? null;

            if ($type === 'image' && is_array($value)) {
                foreach ($value as $image) {
                    if (isset($image['id']) && is_numeric($image['id'])) {
                        $imageIds[] = (int) $image['id'];
                    }
                }

                return;
            }

            if (in_array($type, ['text', 'glossary'], true) && is_string($value)) {
                array_push($imageIds, ...self::getInlineImageIds($value));
            }
        });

        return array_values(array_unique(array_filter($imageIds, static fn ($imageId) => $imageId > 0)));
    }

    public static function imageIdsFromContent(array $content = []): array
    {
        return self::getImageIds($content);
    }

    protected static function getInlineImageIds(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $document->loadHTML(
                '<?xml encoding="UTF-8"><div>' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );

            $ids = [];
            foreach ($document->getElementsByTagName('img') as $image) {
                $uploadId = $image->getAttribute('data-upload-id');

                if ($uploadId !== '' && ctype_digit($uploadId)) {
                    $ids[] = (int) $uploadId;
                }
            }

            return $ids;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Detach images inside contents
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-12-04, 23:22 ICT
     */
    public static function detachImagesInsideContent(mixed $model = null): void
    {
        //  Check if model is null or not exits ['content'] or ['content'] is not an array
        if (!$model || !isset($model->content) || !is_array($model->content)) {
            return;
        }

        DB::beginTransaction();
        try {
            //  Detach all images by remove from UploadTarget
            UploadTarget::query()
                ->where([
                    'target_id' => $model->id,
                    'target_type' => get_class($model),
                    'category' => 'content',
                ])
                ->where(function ($query) use ($model) {
                    return $query
                        ->whereIn('upload_id', self::getImageIds($model->content))
                        ->orWhere('upload_id', null);
                })
                ->delete();
        } catch (Exception $e) {
            Log::error('Cannot Detach images inside content', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            DB::rollBack();
            return;
        }

        DB::commit();
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'attach-and-detach-images';
    }
}
