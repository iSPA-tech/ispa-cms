<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use JsonException;
use Throwable;
use Cms\Core\Models\Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Services\UploadR2EagerSyncService;
use App\Services\AdminUpdateContentService;
use Cms\Content\Interfaces\ContentInterface;
use Psr\Container\NotFoundExceptionInterface;
use Cms\Core\Http\Controllers\AdminController;
use Psr\Container\ContainerExceptionInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Http\Requests\Upload\NewUploadRequest;
use Cms\Core\Http\Resources\Upload\UploadCollection;
use Cms\Core\Http\Requests\Upload\StoreUploadRequest;
use Cms\Core\Http\Requests\Upload\UpdateUploadRequest;
use Cms\Core\Http\Requests\Upload\SetMainUploadRequest;
use Cms\Core\Http\Requests\Upload\GetUploadListRequest;
use Cms\Core\Http\Requests\Upload\PostUploadByTypeRequest;
use Cms\Core\Http\Requests\Upload\MultiDeleteUploadRequest;

/**
 * Handle media uploads and management for Admin v2: upload by type,
 * list with rich filters, delete single/multiple, manage folder trees,
 * and log audit events for media actions.
 *
 * @see UploadInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-18)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class UploadController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param UploadInterface $model
     * @param CategoryTargetInterface $target
     * @param ContentInterface $content
     *
     * @author malayvuong
     *
     * @since 1.0.0
     * @last_updated 6.47.5 - 2022-08-08, 12:35 ICT - by malayvuong
     */
    public function __construct(
        protected UploadInterface         $model,
        protected CategoryTargetInterface $target,
        protected ContentInterface        $content)
    {
        parent::__construct();
    }

    /**
     * Provide aggregated media statistics for the admin dashboard.
     * @return mixed
     */
    public function dashboard(): mixed
    {
        if (!auth()->user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $shouldReload = filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN);

        if ($shouldReload) {
            cache()->forget('core.upload.dashboard');
        }

        $dashboard = cache()->remember('core.upload.dashboard', 300, function (): array {
            $now = Carbon::now();
            $since24h = $now->copy()->subDay();

            $totals = Upload::query()
                ->selectRaw('COUNT(*) as total_files')
                ->selectRaw('COALESCE(SUM(file_size), 0) as total_bytes')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as new_uploads_24h',
                    [$since24h]
                )
                ->first();

            $totalFiles = (int)($totals?->total_files ?? 0);
            $totalBytes = (int)($totals?->total_bytes ?? 0);
            $newUploads24h = (int)($totals?->new_uploads_24h ?? 0);

            $typeAggregates = Upload::query()
                ->select('type', DB::raw('COUNT(*) as total'), DB::raw('SUM(file_size) as bytes'))
                ->groupBy('type')
                ->get();

            $counts = [
                'image' => 0,
                'video' => 0,
                'audio' => 0,
                'document' => 0,
                'archive' => 0,
                'other' => 0,
            ];

            $typeBytes = [
                'image' => 0,
                'video' => 0,
                'audio' => 0,
                'document' => 0,
                'archive' => 0,
                'other' => 0,
            ];

            foreach ($typeAggregates as $row) {
                $normalized = $this->normalizeType($row->type);
                $counts[$normalized] = ($counts[$normalized] ?? 0) + (int)$row->total;
                $typeBytes[$normalized] = ($typeBytes[$normalized] ?? 0) + (int)$row->bytes;
            }

            $averageSize = $totalFiles > 0 ? (int)round($totalBytes / $totalFiles) : 0;

            $capacityBytes = (int)config('filesystems.media.capacity_bytes', 0);

            $typeDistribution = collect($counts)
                ->map(fn(int $total, string $type) => [
                    'key' => $type,
                    'label' => $this->formatTypeLabel($type),
                    'count' => $total,
                    'bytes' => $typeBytes[$type] ?? 0,
                    'percent' => $totalFiles > 0 ? round(($total / $totalFiles) * 100, 2) : 0,
                ])
                ->values();

            $recentUploads = Upload::query()
                ->select('id', 'name', 'type', 'mime_type', 'file_size', 'created_at', 'user_id')
                ->with(['uploader:id,name'])
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn(Upload $upload) => [
                    'id' => $upload->id,
                    'name' => $upload->name,
                    'type' => $upload->type,
                    'type_label' => $this->formatTypeLabel($this->normalizeType($upload->type ?? $upload->mime_type)),
                    'icon' => $this->iconForType($this->normalizeType($upload->type ?? $upload->mime_type)),
                    'file_size' => (int)$upload->file_size,
                    'display_size' => file_size_format((int)$upload->file_size),
                    'created_at' => optional($upload->created_at)->toDateTimeString(),
                    'created_label' => optional($upload->created_at)->format('d/m/Y H:i'),
                    'uploader' => $upload->uploader?->name,
                ])
                ->values();

            $largestUploads = Upload::query()
                ->select('id', 'name', 'type', 'mime_type', 'file_size', 'created_at')
                ->orderByDesc('file_size')
                ->limit(6)
                ->get()
                ->map(fn(Upload $upload) => [
                    'id' => $upload->id,
                    'name' => $upload->name,
                    'type' => $upload->type,
                    'type_label' => $this->formatTypeLabel($this->normalizeType($upload->type ?? $upload->mime_type)),
                    'file_size' => (int)$upload->file_size,
                    'display_size' => file_size_format((int)$upload->file_size),
                ])
                ->values();

            $unusedUploads = Upload::query()
                ->select('id', 'name', 'file_size', 'created_at')
                ->whereDoesntHave('pivots')
                ->orderByDesc('file_size')
                ->limit(6)
                ->get()
                ->map(fn(Upload $upload) => [
                    'id' => $upload->id,
                    'name' => $upload->name,
                    'file_size' => (int)$upload->file_size,
                    'display_size' => file_size_format((int)$upload->file_size),
                    'created_at' => optional($upload->created_at)->toDateTimeString(),
                ])
                ->values();

            return [
                'refreshed_at' => $now->toDateTimeString(),
                'stats' => [
                    'total_files' => $totalFiles,
                    'total_bytes' => $totalBytes,
                    'average_bytes' => $averageSize,
                    'average_size_label' => file_size_format($averageSize),
                    'new_uploads_24h' => $newUploads24h,
                    'counts' => $counts,
                    'capacity_bytes' => $capacityBytes,
                    'total_size_label' => file_size_format($totalBytes),
                ],
                'type_distribution' => $typeDistribution,
                'recent_uploads' => $recentUploads,
                'largest_uploads' => $largestUploads,
                'unused_uploads' => $unusedUploads,
            ];
        });

        return ires()->data($dashboard);
    }

    /**
     * Upload file by type
     *
     * @param string $type
     * @param PostUploadByTypeRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-06-08, 19:34 ICT
     */
    public function uploadByType(string $type, PostUploadByTypeRequest $request): mixed
    {
        try {
            $disk = config('filesystems.default', 'public');
            $type = Str::lower($type);
            //  Process to upload & move file
            $file = $request->file('file');
            $fileExtension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $fileMimeType = $file->getMimeType();
            // Determine if real file is an image (handle page context as well)
            $isImage = Str::startsWith($fileMimeType, 'image/');
            if ($isImage) {
                $fileDimension = @getimagesize($file->getRealPath());
            }
            $uploadFolder = Str::lower($type . '/' . now()->format('Y/m'));
            $fileName = str($file->getClientOriginalName())
                    ->replaceLast($fileExtension, '')
                    ->slug() . '-' . time();
            $fullFileName = $fileName . '.' . $fileExtension;
            //  Make directory if not exists
            //  Explicit 0775 matches config/filesystems.php's public disk dir
            //  permission; the implicit 0755 default strips group-write, so a
            //  non-owner CLI process can't write into a dir this created.
            File::ensureDirectoryExists(Storage::disk($disk)->path($uploadFolder), 0775);
            //  Process to save file into host by Storage (Not optimized)
            Storage::disk($disk)->putFileAs($uploadFolder, $file, $fullFileName);
            //  Prepare data to save into db
            $targetFolder = $request->input('folder', $type === 'page' ? 'page' : ($type === 'avatar' ? 'avatar' : 'images'));
            $data = [
                'disk' => $disk,
                'type' => $type,
                'name' => $fullFileName,
                'path' => $uploadFolder,
                'extension' => $fileExtension,
                'file_size' => $fileSize,
                'mime_type' => $fileMimeType,
                'user_id' => user('id'),
                'target_folder' => $targetFolder,
                'category' => str($request->input('category', $targetFolder))->lower(),
                'main' => (bool)$request->input('main', false),
                'dimensions' => [
                    'width' => $fileDimension[0] ?? 0,
                    'height' => $fileDimension[1] ?? 0,
                ],
                'config' => [
                    'requiredLogin' => false,
                    'allowComment' => false,
                    'featured' => false,
                    'showApi' => false,
                    'roles' => [],
                ],
                'custom' => [
                    'view' => 0,
                    'downloaded' => 0,
                    'priority' => 0,
                ],
            ];

            $created = $this->model->create($data);
            $this->warmUploadPreviewVariants($created);

            event(new AuditLogEvent('upload.' . $type, $created->toArray(), Upload::class, $created->id, 'fa fa-cloud-upload', 'success'));
        } catch (Exception $e) {
            Log::debug('Upload failed:' . $e->getMessage(), $e->getTrace());

            return ires()->error()->message('Cannot upload file with error message: ' . $e->getMessage());
        }

        return ires()->data($created);
    }

    /**
     * Get folder list by db
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-05-02, 18:55 ICT
     */
    public function folderList()
    {
        $type = request('type', 'all');

        $data = $this->model->model()->when(
            !empty($type) && in_array($type, ['image', 'video', 'audio', 'document', 'archive', 'page'], true),
            fn($query) => $query->where('type', $type)->when(
                $type === 'image',
                fn($q) => $q->where('category', '!=', 'commentImages')
            )
        )->select('path')->groupBy('path')->orderBy('path', 'desc')->get();

        $data->map(function (Upload $item): Upload {
            $item->setAppends([]);

            return $item;
        });

        //  Get only path from collection
        $data = $data->pluck('path')->toArray();

        $folders = $this->parsePathsToTree($data);

        return ires()->data($folders);
    }

    /**
     * Parse paths to folder tree
     *
     * @param array $paths
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2023-06-13, 19:50 ICT
     */
    public function parsePathsToTree(array $paths = []): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $currentNode = &$tree;

            foreach ($parts as $index => $part) {
                if ($index > 0) {
                    $key = implode('/', array_slice($parts, 0, array_search($part, $parts) + 1));

                    if (!isset($currentNode[$key])) {
                        $currentNode[$key] = [
                            'title' => $part,
                            'key' => $key,
                            'children' => [],
                        ];
                    }

                    $currentNode = &$currentNode[$key]['children'];
                }
            }
        }
        //  Create function remove empty children
        $removeEmptyChildren = static function (&$node) use (&$removeEmptyChildren) {
            if (empty($node['children'])) {
                unset($node['children']);
            } else {
                foreach ($node['children'] as $key => $child) {
                    $removeEmptyChildren($node['children'][$key]);
                }
            }
        };
        //  Create function remove string key in children array
        $removeStringKeyInChildren = static function (&$node) use (&$removeStringKeyInChildren) {
            if (isset($node['children'])) {
                $node['children'] = array_values($node['children']);
                foreach ($node['children'] as $key => $child) {
                    $removeStringKeyInChildren($node['children'][$key]);
                }
            }
        };

        $newTree = [
            'children' => $tree,
        ];
        $removeEmptyChildren($newTree);
        $removeStringKeyInChildren($newTree);

        return $newTree['children'] ?? [];
    }

    /**
     * New Upload optimized image
     *
     * @param NewUploadRequest $request
     *
     * @return mixed
     * @throws JsonException
     * @since 6.62.6 - 2022-10-30, 10:59 ICT
     * @author malayvuong
     */
    public function newUpload(NewUploadRequest $request): mixed
    {
        $disk = 'public';
        $uploaded = [];
        foreach ($request->file('images') as $file) {
            //  Process to upload & move file
            $uploadFolder = Str::lower($request->input('folder', 'images') . '/' . id() . '/' . now()->format('Y-m-d'));
            $fileName = str()->uuid();
            $extension = '.jpg';

            Storage::disk($disk)->makeDirectory($uploadFolder);

            //  Check the optimized quota limit
//            $tinyOptimized = new OptimizedImageService();
//            if ($tinyOptimized->count() > 0) {
//                (new OptimizedImageService())->optimize($file, $uploadFolder.'/', $fileName, $extension);
//            } else {
//                $extension = '.' . $file->getExtension();
            //  Process to save image into host by Storage (Not optimized)
            Storage::disk($disk)->putFileAs($uploadFolder, $file, $fileName . $extension);
//            }

            $imageInfo = getimagesize(Storage::disk('public')->path($uploadFolder . '/' . $fileName . $extension));

            $size = Storage::disk('public')->size($uploadFolder . '/' . $fileName . $extension);

            //  Process to save to database
            $upload = $this->model->create([
                'disk' => 'public',
                'type' => 'image',
                'name' => $fileName . $extension,
                'path' => $uploadFolder,
                'extension' => str($extension)->replace('.', ''),
                'file_size' => $size,
                'mime_type' => $imageInfo['mime'],
                'user_id' => id(),
                'target_folder' => $request->input('folder', 'images'),
                'category' => str($request->input('category', $request->input('folder', 'images')))->lower(),
                'target_id' => $request->input('targetId', 0),
                'main' => (bool)$request->input('main', false),
                'dimensions' => [
                    'width' => $imageInfo[0] ?? 0,
                    'height' => $imageInfo[1] ?? 0,
                ],
                'target_type' => 'App\Models\\' . ucfirst($request->input('folder', 'images')),
                'config' => [
                    'requiredLogin' => false, 'allowComment' => false,
                    'featured' => false, 'showApi' => false,
                    'roles' => [],
                ],
                'custom' => ['view' => 0, 'downloaded' => 0, 'priority' => 0],
            ]);

            $this->warmUploadPreviewVariants($upload);
            $uploaded[] = $upload;
        }

        return ires()->data($uploaded);
    }

    private function warmUploadPreviewVariants(Upload $upload): void
    {
        try {
            $variantService = app(ImageVariantService::class);
            // Original first (already on disk from the write above), then every variant this
            // upload's contexts actually produce — smallest-first ordering happens inside
            // UploadR2EagerSyncService, not here.
            $paths = [$upload->getTargetFile()];

            foreach ($variantService->contextsForUpload($upload) as $context) {
                foreach ($variantService->ensureContext($upload, $context) as $variant) {
                    if (($variant['status'] ?? null) === 'ready' && !empty($variant['path'])) {
                        $paths[] = $variant['path'];
                    }
                }
            }

            app(UploadR2EagerSyncService::class)->syncEagerly($upload->id, $paths, $upload->disk ?: 'public');
        } catch (Throwable $e) {
            Log::warning('Upload preview variant warmup failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set main file
     *
     * @param int $id
     * @param SetMainUploadRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-09-13, 19:08 ICT
     */
    public function setMain(int $id, SetMainUploadRequest $request): mixed
    {
        // First set all files to not main
        $this->model->update([['id', 'IN', $request->get('list', [])]], ['main' => false]);

        // Then set main file
        $this->model->update(['id' => $id], ['main' => true]);

        return ires();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateUploadRequest $request
     *
     * @return mixed
     * @last_updated 6.47.5 - 2022-08-08, 12:35 ICT - by malayvuong
     * @throws ContainerExceptionInterface
     * @author malayvuong
     */
    public function update(int $id, UpdateUploadRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        DB::beginTransaction();

        try {
            $model->save();

            $service = new AdminUpdateContentService();

            $service->syncCategory($request, $model);
            $service->syncContents($request, $model);

            //  If store category is files, clear related cache keys via cache helper (CacheTag tracking removed)
            if ($model->category === 'files') {
            }
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        Db::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.uploads')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     * @throws ContainerExceptionInterface
     * @author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        //  Todo: Set up your model information here
        $model->fill(request()->all());

        try {
            $model->config = request()->get('config', []);
        } catch (NotFoundExceptionInterface $e) {
            Log::debug($e->getMessage());
            $model->config = [];
        }

        return $model;
    }

    /**
     * Quick Update resource.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function quickUpdate(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => null/** Todo: change your name here */]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => null/** Todo: change your name here */]));
    }

    /**
     * Switch a status of resource.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function switch(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => null/** Todo: change your name here */]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => null/** Todo: change your name here */]));
    }

    /**
     * Display a listing of the resource.
     *
     * @param GetUploadListRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function index(GetUploadListRequest $request): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $keywords = str($request->input('keywords'))->trim()->replace(' ', '%');

        $model = $this->model->get([
            'select' => [
                'id', 'name', 'name_alias', 'path', 'type', 'mime_type', 'target_id', 'file_size', 'category',
                'deleted_at', 'custom', 'config', 'created_at', 'user_id', 'dimensions',
            ],
            'page' => request('pageSize', 20),
//            'filter' => request()->input(),
            'latest' => true,
            'when' => [
                [
                    // Uploads kept on a private disk (for example the Digital Library document store) never
                    // show in the general media center; rows without a disk stay visible.
                    !empty(config('filesystems.private_upload_disks', [])),
                    fn($q) => $q->where(fn($q) => $q->whereNull('disk')
                        ->orWhereNotIn('disk', (array) config('filesystems.private_upload_disks', []))),
                ],
                [
                    !empty($request->input('type')) && $request->input('type') !== 'all',
                    fn($q) => $q->where('type', $request->input('type'))->when(
                        $request->input('type') === 'image',
                        fn($q) => $q->where('category', '!=', 'commentImages')
                    ),
                ],
                [
                    !empty($keywords),
                    fn($q) => $q->where(fn($q) => $q->where('name', 'like', '%' . $keywords . '%')
                        ->orWhere('name_alias', 'like', '%' . $keywords . '%')),
                ],
                [
                    !empty($request->input('view_folder', '')),
                    fn($q) => $q->where('path', 'like', $request->input('view_folder', '') . '%'),
                ],
                [
                    $request->input('owner') === 'mine',
                    fn($q) => $q->where('user_id', user('id')),
                ],
                [
                    $request->input('usage') === 'used',
                    fn($q) => $q->has('pivots'),
                ],
                [
                    $request->input('usage') === 'unused',
                    fn($q) => $q->doesntHave('pivots'),
                ],
                [
                    !empty($request->input('target_folder')),
                    fn($q) => $q->where('target_folder', $request->input('target_folder')),
                ],
                [
                    !empty($request->input('created_from')),
                    fn($q) => $q->whereDate('created_at', '>=', $request->date('created_from')),
                ],
                [
                    !empty($request->input('created_to')),
                    fn($q) => $q->whereDate('created_at', '<=', $request->date('created_to')),
                ],
            ],
            'count' => ['pivots'],
            'with' => [
                'uploader:id,name,username',
            ],
        ]);

        return ires()->data(new UploadCollection($model));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreUploadRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreUploadRequest $request): mixed
    {
        $disk = 'public';

        //  Process to upload & move file
        $upload = request()->file;
        $uploadFolder = Str::lower(request('target', 'images') . '/' . id() . '/' . now()->format('Y-m-d'));
        $fileName = time() . '-' . str(str_replace($upload->extension(), '', request('name')))->slug() . '.' . $upload->extension();

        $path = Storage::disk($disk)->putFileAs($uploadFolder, $upload, $fileName);

        if (!$path) {
            return ires()->error()->message(__('mgs.error.file.upload'));
        }

        $model = $this->model->model();
        $model->name = $fileName;
        $model->path = $uploadFolder;
        $model->disk = $disk;
        $model->type = $upload->extension();
        $model->mime_type = $upload->getClientMimeType();
        $model->file_size = $upload->getSize();
        $model->target_folder = request('target');
        $model->category = Str::lower(request('category', $model->target_folder));
        $model->dimensions = [
            'width' => request('width', 0),
            'height' => request('height', 0),
        ];
        $model->target_type = 'App\Models\\' . $model->target_folder;
        $model->main = false;
        $model->target_id = request('targetId', 0);
        $model->user_id = id();

        //  Set default config and custom
        $model->config = [
            'requiredLogin' => false, 'allowComment' => false,
            'featured' => false, 'showApi' => false,
            'roles' => [],
        ];
        $model->custom = ['view' => 0, 'downloaded' => 0, 'priority' => 0];

        if ($model->category === 'avatar') {
            $model->target_id = id();
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.error.file.saved'));
        }

        $model->fresh();

        //  If store category is files, clear related cache keys via cache helper (CacheTag tracking removed)
        if ($model->category === 'files') {
        }

        event(new AuditLogEvent('upload.' . $model->target_folder, $model->toArray(), Upload::class, $model->id, 'fa fa-cloud-upload', 'success'));

        return ires()->data($model);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()->where([
            'id' => $id,
        ])->when('target_id=0', function ($q) {
            return $q->with([
                'target:id,name',
            ]);
        })->when((bool)request('isDelete', false) === true, function (Builder $q): Builder {
            return $q->withTrashed();
        })->with(['contents', 'uploader:id,name', 'categories:id,name'])->first();

        return ires()->data($model);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => null/** Todo: change your name here */]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => null/** Todo: change your name here */]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteUploadRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteUploadRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => null/** Todo: change your name here */]));
    }

    protected function normalizeType(?string $value): string
    {
        $candidate = Str::lower((string)$value);

        if ($candidate === '') {
            return 'other';
        }

        return match (true) {
            str_contains($candidate, 'image') => 'image',
            str_contains($candidate, 'video') => 'video',
            str_contains($candidate, 'audio') => 'audio',
            str_contains($candidate, 'pdf'),
            str_contains($candidate, 'doc'),
            str_contains($candidate, 'sheet'),
            str_contains($candidate, 'text') => 'document',
            str_contains($candidate, 'zip'),
            str_contains($candidate, 'rar'),
            str_contains($candidate, '7z'),
            str_contains($candidate, 'archive') => 'archive',
            default => $candidate,
        };
    }

    protected function formatTypeLabel(string $type): string
    {
        $map = [
            'image' => 't.type.image',
            'video' => 't.type.video',
            'audio' => 't.type.audio',
            'document' => 't.type.document',
            'archive' => 't.type.archive',
        ];

        $key = $map[$type] ?? null;

        if ($key) {
            $translated = __($key);
            if ($translated !== $key) {
                return $translated;
            }
        }

        return Str::headline($type);
    }

    protected function iconForType(string $type): string
    {
        return match ($type) {
            'image' => 'images',
            'video' => 'video',
            'audio' => 'audio',
            'document' => 'document',
            'archive' => 'archive',
            default => 'file',
        };
    }
}
