<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Cms\Core\Interfaces\UploadInterface;
use V3\Requests\Admin\Upload\GetUploadRequest;
use V3\Resources\Admin\Upload\UploadCollection;
use V3\Requests\Admin\Upload\UploadImageRequest;
use V3\Resources\Admin\Upload\UploadPageCollection;

class UploadController extends Controller
{
    /**
     * UploadController Constructor
     *
     * @param UploadInterface $upload
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-02, 16:54 ICT
     */
    public function __construct(
        protected UploadInterface $upload,
    )
    {
        parent::__construct();
    }

    /**
     * Get upload list
     *
     * @param GetUploadRequest $request
     *
     * @return mixed
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 14:54 ICT
     */
    public function index(GetUploadRequest $request)
    {
        $model = $this->upload->get([
            'page' => $request->input('perPage', 10),
            'latest' => true,
            'when' => [
                [
                    // Uploads on a private disk (e.g. the Digital Library document store) never show in media lists.
                    !empty(config('filesystems.private_upload_disks', [])),
                    fn($query) => $query->where(fn($query) => $query->whereNull('disk')
                        ->orWhereNotIn('disk', (array) config('filesystems.private_upload_disks', []))),
                ],
                [
                    !empty($request->input('target')) && !empty($request->input('targetId')) && (int)$request->input('targetId') > 0,
                    fn($query) => $query->whereHas(
                        'target' . str($request->input('target'))->ucfirst(),
                        function ($query) use ($request): mixed {
                            $query->where('target_id', $request->input('targetId'))
                                ->when(
                                    !empty($request->input('category')),
                                    fn($query) => $query->where('category', $request->input('category'))
                                );
                        }
                    ),
                ],
                [
                    !empty($request->input('category')),
                    fn($query) => $query->where('category', $request->input('category')),
                ],
                [
                    !empty($request->input('type')),
                    fn($query) => $query->where('mime_type', 'like', $request->input('type') . '/%'),
                ],
            ],
        ]);

        return ires()->data(new UploadPageCollection($model));
    }

    /**
     * Upload Image
     *
     * @param UploadImageRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-04-02, 16:56 ICT
     */
    public function uploadImage(UploadImageRequest $request): mixed
    {
        $disk = 'public';
        $target = $request->input('target', 'images');
        $uploaded = [];

        foreach ($request->file('images') as $index => $file) {
            try {
                //  Process to upload & move file
                $uploadFolder = Str::lower($target . '/' . now()->format('Y-m-d'));
                $extension = '.' . ((
                    !empty($file->getClientOriginalExtension())
                        ? $file->getClientOriginalExtension()
                        : (str($file->getMimeType())->replace('image/', ''))
                    ) ?? 'jpg');
                $fileName = str($file->getClientOriginalName())->replace($extension, '') ?? str()->uuid();

                $fullPath = $uploadFolder . '/' . $fileName . $extension;

                if (!Storage::disk($disk)->exists($uploadFolder)) {
                    Storage::disk($disk)->makeDirectory($uploadFolder);
                }

                Storage::disk($disk)->putFileAs($uploadFolder, $file, $fileName . $extension);

                $imageInfo = getimagesize(Storage::disk('public')->path($fullPath));

                $size = Storage::disk('public')->size($fullPath);

                //  Process to save to database
                $upload = $this->upload->create([
                    'disk' => 'public',
                    'name' => $fileName . $extension,
                    'path' => $uploadFolder,
                    'type' => str($extension)->replace('.', ''),
                    'file_size' => $size,
                    'mime_type' => $imageInfo['mime'],
                    'user_id' => auth()->id(),
                    'target_folder' => $target,
                    'category' => str($request->input('category', $target))->lower(),
                    'target_id' => (int)$request->input('target_id', 0),
                    'main' => $request->input('main', 'false') === true,
                    'dimensions' => [
                        'width' => $imageInfo[0] ?? 0,
                        'height' => $imageInfo[1] ?? 0,
                    ],
                    'target_type' => 'App\Models\\' . ucfirst($target),
                    'config' => [
                        'requiredLogin' => false, 'allowComment' => false,
                        'featured' => false, 'showApi' => false,
                        'roles' => [],
                    ],
                    'custom' => ['view' => 0, 'downloaded' => 0, 'priority' => 0],
                ]);

                $upload->setAppends([
                    'thumbnail_sm', 'cover_sm', 'size', 'url',
                ]);

                $uploaded[$index] = $upload;
            } catch (Exception $e) {
                $uploaded[$index] = [
                    'error' => $e->getMessage(),
                    'id' => 0,
                ];
            }
        }

        return ires()->data(new UploadCollection($uploaded));
    }
}
