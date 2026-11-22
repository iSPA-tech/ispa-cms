<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Preview image files of hub bundles on the public disk, shared by {@see WidgetHubInstaller} and the switch-over
 * command. Files are written to request-unique temporary names first and moved to their versioned names only after the
 * database commit; a request only ever deletes the temporary files it wrote itself.
 */
class WidgetHubPreviewFiles
{
    /**
     * Write the bundle's design previews (optionally only `$onlyThemeKeys`) to temporary names.
     *
     * @param array<string, string> $written final path => temporary path (appended to)
     * @param list<string>|null $onlyThemeKeys
     * @return array<string, string> theme_key => public disk path
     */
    public function write(WidgetBundle $bundle, array &$written, ?array $onlyThemeKeys = null): array
    {
        $paths = [];
        foreach ($bundle->designs as $design) {
            if ($design['preview_png_base64'] === null || ($onlyThemeKeys !== null && !in_array($design['theme_key'], $onlyThemeKeys, true))) {
                continue;
            }
            $path = WidgetHubInstaller::PREVIEW_DIR . '/' . WidgetHubInstaller::safeName($bundle->widgetKey) . '/'
                . WidgetHubInstaller::safeName($design['theme_key']) . '-v' . $bundle->version . '.png';
            $written[$path] = $this->putTemporary($path, (string) base64_decode($design['preview_png_base64'], true));
            $paths[$design['theme_key']] = $path;
        }

        return $paths;
    }

    /** Write bytes next to `$path` under a name only this request uses; returns that name. */
    public function putTemporary(string $path, string $bytes): string
    {
        $temporary = $path . '.tmp-' . Str::random(16);
        Storage::disk('public')->put($temporary, $bytes);

        return $temporary;
    }

    /**
     * After commit: each temporary file becomes its versioned name. A version's files never change (same signed
     * bundle), so a name that already exists is kept and the temporary copy dropped.
     *
     * @param array<string, string> $written final path => temporary path
     */
    public function moveIntoPlace(array $written): void
    {
        $disk = Storage::disk('public');
        foreach ($written as $final => $temporary) {
            if ($disk->exists($final)) {
                $disk->delete($temporary);
            } else {
                $disk->move($temporary, $final);
            }
        }
    }

    /**
     * Delete only this request's temporary files.
     *
     * @param array<string, string> $written final path => temporary path
     */
    public function deleteFiles(array $written): void
    {
        if ($written !== []) {
            Storage::disk('public')->delete(array_values($written));
        }
    }
}
