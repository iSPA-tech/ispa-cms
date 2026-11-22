<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers;

use JsonException;
use Illuminate\Http\Request;
use Cms\Content\Models\Post;
use Illuminate\Http\Response;
use Cms\Core\Models\Category;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Routing\Controller;
use Cms\Core\Models\CategoryTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;


/**
 * Core admin controller providing shared utilities for the CMS admin panel.
 *
 * Serves as the base class for 68+ V2 Admin controllers across all CMS modules.
 * Provides translation file serving, module.json delivery, recursive category
 * child resolution, and QR code generation endpoints.
 *
 * @see \Cms\Core\Models\Category
 * @see \Cms\Core\Facades\CMSLogger
 * @package Cms\Core\Http\Controllers
 * @author  iSPA Team
 * @since   1.0.0
 */
class AdminController extends Controller
{
    /**
     * Controller constructor
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct()
    {
    }

    /**
     * Check whether a category–post association already exists.
     *
     * @param  int  $catId   Category ID
     * @param  int  $postId  Post (target) ID
     * @return bool
     */
    private function isExists($catId, $postId)
    {
        return CategoryTarget::where([
            'target_id' => $postId,
            'category_id' => $catId,
            'target_type' => Post::class,
        ])->exists();
    }

    /**
     * Recursively flatten a nested category tree into a flat array of IDs.
     *
     * Traverses the `children` key of each item and collects all IDs
     * into a single-level array for bulk operations.
     *
     * @param  array  $data    Nested category items (each must have `id` and optional `children`)
     * @param  array  $return  Accumulator (used internally during recursion)
     * @return array           Flat array of category IDs
     */
    public function a__multi(array $data = [], array $return = []): array
    {
        foreach ($data as $item) {
            $return = [...$return, $item['id']];

            if (!empty($item['children'])) {
                $return = $this->a__multi($item['children']->toArray(), $return);
            }
        }

        return $return;
    }

    /**
     * Return json file of translate key
     *
     * @param string $version
     * @param string $path
     * @param string $locale
     * @param string $character
     *
     * @return BinaryFileResponse
     * @throws JsonException
     * @since  1.0.0
     * @author malayvuong
     */
    /**
     * Public manifest for mobile apps: current i18n pack version plus the
     * latest released app versions per platform (for update banners).
     */
    public function appI18nManifest(): \Illuminate\Http\JsonResponse
    {
        $mobile = (array) config('ispa-cms.mobile_app', []);
        $platforms = [];
        foreach (['android', 'ios'] as $platform) {
            $info = array_filter(
                (array) ($mobile[$platform] ?? []),
                static fn ($value): bool => $value !== null && $value !== '',
            );
            if ($info !== []) {
                $platforms[$platform] = $info;
            }
        }

        return response()
            ->json([
                'i18n' => [
                    'version' => (string) config('ispa-cms.app_version', '0.0.0'),
                ],
                'app' => $platforms === [] ? (object) [] : $platforms,
                'generated_at' => now()->toISOString(),
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function locales(string $version = '1.0.0', string $path = 'adm', string $locale = 'vi', string $character = 'a'): \Symfony\Component\HttpFoundation\Response
    {
        $version = trim($version);
        $path = trim($path);
        $locale = trim($locale);
        $character = trim($character);

        $filePath = Storage::disk('public')->path("i18n/$version/$path/$locale/$character.json");

        $originalPath = '' . $filePath;

        if (!file_exists($filePath)) {
            $filePath = Storage::disk('public')->path("i18n/$version/$path/$locale.json");
        }

        if (!file_exists($filePath)) {
            CMSLogger::info([
                'message' => 'Translation file not found.',
                'originalPath' => $originalPath,
                'filePath' => $filePath,
                'version' => $version,
                'path' => $path,
                'locale' => $locale,
                'character' => $character,
            ]);

            abort(404, 'Translation file not found.');
        }

        // ETag revalidation instead of a long blind TTL: language packs are
        // regenerated in place under the SAME versioned URL, so a 7-day
        // max-age left admins reading stale keys for up to a week. With an
        // mtime+size ETag the browser still caches, but every page load is a
        // cheap conditional request — fresh packs apply immediately, an
        // unchanged pack costs a 304.
        $etag = '"' . md5(filemtime($filePath) . '|' . filesize($filePath)) . '"';
        $cacheControl = 'public, max-age=300, must-revalidate';

        if (trim((string) request()->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => $cacheControl]);
        }

        return response()->file($filePath, [
            'Content-Type' => 'application/json',
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }

    /**
     * Return aggregated module.json for a given CMS module and locale
     *
     * @param string $module
     * @param string $locale
     *
     * @return BinaryFileResponse
     */
    public function moduleJson(string $module, string $locale = 'vi'): BinaryFileResponse
    {
        $module = trim($module);
        $locale = trim($locale);
        $moduleBase = base_path('cms');
        $moduleDir = $moduleBase . DIRECTORY_SEPARATOR . $module;

        if (!file_exists($moduleDir) || !is_dir($moduleDir)) {
            abort(404, 'Module not found.');
        }

        $filePath = $moduleDir . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . 'module.json';

        if (!file_exists($filePath)) {
            abort(404, 'module.json not found for given module/locale.');
        }

        return response()->file($filePath, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Get children ids
     *
     * @param int $id
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2023-05-13, 14:38 ICT
     */
    public function getChildrenIds(int $id): array
    {
        $category = Category::query()->where('id', $id)->select('id')->with(['children:id,parent_id'])->first();

        if ($category) {
            $children = $category->children->pluck('id')->toArray();
            $data = [];
            if (count($children) > 0) {
                foreach ($children as $child) {
                    $data = [...$data, ...$this->getChildrenIds($child)];
                }
            }

            return [...$data, $id];
        }

        return [$id];
    }

    /**
     * Generate QR Code
     *
     * @param Request $request
     *
     * @return Application|ResponseFactory|\Illuminate\Foundation\Application|Response
     * @author malayvuong
     * @since 1.0.0 - 2022-01-23, 10:54
     */
    public function QrGenerate(Request $request)
    {
        return $this->renderQrImage($request->query());
    }

    /**
     * Render a protected QR image from an authenticated request body.
     *
     * Private payloads, including TOTP enrollment URIs, must never be cached
     * or placed in a query string that can reach access logs and browser history.
     */
    public function QrRender(Request $request): Response
    {
        return $this->renderQrImage($request->only([
            'string',
            'type',
            'color',
            'bgColor',
            'bgOpacity',
            'size',
        ]), true);
    }

    private function renderQrImage(array $input, bool $forceNoCache = false): Response
    {
        $validator = Validator::make($input, [
            'string' => ['required', 'string', 'max:2048'],
            'type' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'regex:/\A[0-9A-Fa-f]{6}\z/'],
            'bgColor' => ['nullable', 'regex:/\A[0-9A-Fa-f]{6}\z/'],
            'bgOpacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'size' => ['nullable', 'integer', 'min:120', 'max:800'],
            'noCache' => ['nullable', 'in:true,false,1,0'],
        ]);

        if ($validator->fails()) {
            return response('Invalid QR request.', 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $data = $validator->validated();
        $string = trim((string) $data['string']);
        $type = (string) ($data['type'] ?? '');
        $color = strtolower((string) ($data['color'] ?? '000000'));
        $bgColor = strtolower((string) ($data['bgColor'] ?? 'ffffff'));
        $bgOpacity = (int) ($data['bgOpacity'] ?? 100);
        $size = (int) ($data['size'] ?? 400);
        $noCache = $forceNoCache || filter_var($data['noCache'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $format = $this->qrImageFormat();
        $mime = $format === 'png' ? 'image/png' : 'image/svg+xml';

        [$cr, $cg, $cb] = $this->rgbFromHex($color);
        [$br, $bg, $bb] = $this->rgbFromHex($bgColor);

        if ($noCache) {
            return $this->qrImageResponse(
                $this->generateQrImage($format, $string, $size, [$cr, $cg, $cb], [$br, $bg, $bb], $bgOpacity),
                $mime,
                false
            );
        }

        $cacheHash = hash('sha256', json_encode([
            'format' => $format,
            'string' => $string,
            'type' => $type,
            'color' => $color,
            'bgColor' => $bgColor,
            'bgOpacity' => $bgOpacity,
            'size' => $size,
        ], JSON_THROW_ON_ERROR));
        $cacheKey = "qr-code:image:$cacheHash";

        try {
            $image = Cache::lock("qr-code:lock:$cacheHash", 10)->block(3, function () use ($cacheKey, $format, $string, $size, $cr, $cg, $cb, $br, $bg, $bb, $bgOpacity): string {
                return Cache::remember(
                    $cacheKey,
                    604800,
                    fn (): string => $this->generateQrImage($format, $string, $size, [$cr, $cg, $cb], [$br, $bg, $bb], $bgOpacity)
                );
            });
        } catch (LockTimeoutException) {
            $image = Cache::get($cacheKey);

            if (!is_string($image)) {
                return $this->qrImageResponse(
                    $this->generateQrImage($format, $string, $size, [$cr, $cg, $cb], [$br, $bg, $bb], $bgOpacity),
                    $mime,
                    false
                );
            }
        }

        return $this->qrImageResponse($image, $mime, true);
    }

    private function generateQrImage(string $format, string $string, int $size, array $color, array $backgroundColor, int $backgroundOpacity): string
    {
        return (string) QrCode::format($format)
            ->color($color[0], $color[1], $color[2])
            ->backgroundColor($backgroundColor[0], $backgroundColor[1], $backgroundColor[2], $backgroundOpacity)
            ->margin(1)
            ->size($size)
            ->errorCorrection('H')
            ->generate($string);
    }

    private function qrImageFormat(): string
    {
        return extension_loaded('imagick') ? 'png' : 'svg';
    }

    private function qrImageResponse(string $image, string $mime, bool $publicCache): Response
    {
        $response = response($image, 200)->header('Content-Type', $mime);

        return $publicCache
            ? $response->setMaxAge(604800)->setPublic()
            : $response->header('Cache-Control', 'no-store, private');
    }

    private function rgbFromHex(string $hex): array
    {
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
