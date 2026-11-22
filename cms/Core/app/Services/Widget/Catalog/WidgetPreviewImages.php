<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Hub\WidgetHubInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Preview images of site-owned designs (widget hub distribution Task 6), on the public disk under
 * {@see WidgetHubInstaller::PREVIEW_DIR} like hub previews. A site design always gets a file name of its own
 * (`<widget key>/<theme key>-<random>.<ext>`), so replacing or deleting it never touches a hub file or another
 * design's file. Files are written under a temporary name and renamed into place, as the hub installer does.
 */
class WidgetPreviewImages
{
    /** Largest accepted picture in pixels; read from the file header before anything is decoded. */
    public const MAX_WIDTH = 2400;
    public const MAX_HEIGHT = 4000;

    /**
     * Decode an uploaded PNG or WebP and encode it again (WebP quality 85 when GD can write WebP, else PNG), so only
     * pixels are ever stored: trailing or embedded bytes of the upload never reach the public disk. The pixel size is
     * checked from the header first, so a small file declaring a huge picture is refused before it is decoded.
     *
     * @return array{bytes: string, extension: string}|null null when the bytes are not a decodable PNG/WebP within
     *         {@see MAX_WIDTH} x {@see MAX_HEIGHT}
     */
    public static function reencode(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        $type = $info[2] ?? null;
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $width < 1 || $height < 1 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $webp = function_exists('imagewebp');
        $ok = $webp ? imagewebp($image, null, 85) : imagepng($image, null, 9);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $ok && $encoded !== '' ? ['bytes' => $encoded, 'extension' => $webp ? 'webp' : 'png'] : null;
    }

    /** Public URL of a stored preview path, or null. */
    public static function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk('public')->url($path);
    }

    /**
     * Copy `$path` to a new file owned by the given design. Returns the new path, or null when there is nothing to
     * copy. Inside a transaction the copy is deleted again when that transaction rolls back.
     */
    public function copyFor(?string $path, string $widgetKey, string $themeKey): ?string
    {
        $disk = Storage::disk('public');
        if ($path === null || $path === '' || !$disk->exists($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
        $target = $this->newPath($widgetKey, $themeKey, $extension);
        $this->writeAtomically($target, (string) $disk->get($path));
        $this->deleteOnRollBack($target);

        return $target;
    }

    /**
     * Store `$bytes` (already passed through {@see reencode()}) as the design's preview, clear `preview_stale`, then delete the previous file when no design
     * row references it any more. Concurrent uploads to one design are serialised by the theme row lock.
     */
    public function replace(WidgetTheme $theme, string $bytes, string $extension): WidgetTheme
    {
        $widgetKey = (string) ($theme->widget?->widget_key ?? 'widget-' . $theme->widget_id);
        $target = $this->newPath($widgetKey, (string) $theme->theme_key, $extension);
        $this->writeAtomically($target, $bytes);

        try {
            [$fresh, $previous] = DB::transaction(function () use ($theme, $target): array {
                /** @var WidgetTheme $locked */
                $locked = WidgetTheme::query()->whereKey($theme->getKey())->lockForUpdate()->firstOrFail();
                $previous = $locked->preview_image;
                $locked->forceFill(['preview_image' => $target, 'preview_stale' => false])->save();

                return [$locked, $previous];
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($target);
            throw $e;
        }

        if ($previous !== null && $previous !== '' && $previous !== $target
            && !WidgetTheme::query()->where('preview_image', $previous)->exists()) {
            Storage::disk('public')->delete($previous);
        }

        return $fresh;
    }

    private function newPath(string $widgetKey, string $themeKey, string $extension): string
    {
        return WidgetHubInstaller::PREVIEW_DIR . '/' . WidgetHubInstaller::safeName($widgetKey) . '/'
            . WidgetHubInstaller::safeName($themeKey) . '-' . Str::lower(Str::random(16)) . '.' . $extension;
    }

    private function writeAtomically(string $path, string $bytes): void
    {
        $disk = Storage::disk('public');
        $temporary = $path . '.tmp-' . Str::random(16);
        $disk->put($temporary, $bytes);
        $disk->move($temporary, $path);
    }

    private function deleteOnRollBack(string $path): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(static fn () => Storage::disk('public')->delete($path));
        }
    }
}
