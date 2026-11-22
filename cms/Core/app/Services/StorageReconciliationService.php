<?php

namespace Cms\Core\Services;

use Cms\Core\Models\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageReconciliationService
{
    // Empirically confirmed via SQL + `find` (Prior Finding #12 of the R2 migration plan) — not
    // hardcoded to `image/` alone.
    public const KNOWN_UPLOAD_ROOTS = ['image', 'avatar', 'geoplaces'];

    public function filesForBasePath(string $basePath, string $disk = 'public'): array
    {
        $absoluteDir = Storage::disk($disk)->path(dirname($basePath));
        $baseName = basename($basePath);

        if (!is_dir($absoluteDir)) {
            return [];
        }

        $matches = array_merge(
            [$absoluteDir . '/' . $baseName],                 // the original itself, exact
            glob($absoluteDir . '/' . $baseName . '.*') ?: []  // dot-anchored variants only — NOT a loose prefix match
        );

        return collect($matches)
            ->filter(fn ($absolute) => is_file($absolute))
            ->map(fn ($absolute) => dirname($basePath) . '/' . basename($absolute))
            ->unique()->sort()->values()->all();
    }

    /**
     * Derive the real set of upload roots from the data itself, not a hardcoded guess. Split in
     * PHP rather than a DB-specific SUBSTRING_INDEX() so this stays portable across the mysql
     * connection used in dev/production and the sqlite connection used by the test suite.
     */
    public function discoverUploadRoots(): array
    {
        return DB::table('uploads')
            ->pluck('path')
            ->map(fn (string $path) => explode('/', $path, 2)[0])
            ->unique()->values()->all();
        // Expected (2026-07-12): ['image', 'avatar', 'geoplaces']. A NEW root appearing here that
        // isn't in KNOWN_UPLOAD_ROOTS is exactly the kind of drift this method exists to catch —
        // it's how `geoplaces/` was discovered in the first place during this plan's own preparation.
    }

    public function reconcile(): array
    {
        $report = [
            'unexpected_new_roots' => [],
            'pivot_points_to_missing_upload' => [],
            'upload_missing_original_file' => [],
            'filesystem_orphan_files' => [],
            'variant_without_original' => [],
        ];

        $actualRoots = $this->discoverUploadRoots();
        $report['unexpected_new_roots'] = array_values(array_diff($actualRoots, self::KNOWN_UPLOAD_ROOTS));

        $report['pivot_points_to_missing_upload'] = DB::table('upload_target_pivot as utp')
            ->leftJoin('uploads', 'uploads.id', '=', 'utp.upload_id')
            ->whereNull('uploads.id')
            ->pluck('utp.upload_id')->unique()->values()->all();

        foreach (Upload::query()->cursor() as $upload) {
            // Confirmed against Upload::getTargetFile(): the full relative file path is
            // `path . '/' . name`, NOT `path` alone — `path` is just the directory
            // (e.g. `image/2026/07`), `name` is the filename.
            $basePath = $upload->getTargetFile();
            $absoluteOriginal = Storage::disk($upload->disk ?: 'public')->path($basePath);
            $files = $this->filesForBasePath($basePath, $upload->disk ?: 'public');

            if (!is_file($absoluteOriginal)) {
                $report['upload_missing_original_file'][] = $upload->id;
                if (count($files) > 0) {
                    $report['variant_without_original'][] = $upload->id;
                }
            }
        }

        // Built in PHP rather than a DB-specific CONCAT() so this stays portable across mysql
        // and the sqlite test connection.
        $knownBasePaths = Upload::query()
            ->get(['path', 'name'])
            ->map(fn (Upload $u) => $u->path . '/' . $u->name)
            ->flip();

        foreach (self::KNOWN_UPLOAD_ROOTS as $root) {
            $diskRoot = Storage::disk('public')->path($root);
            if (!is_dir($diskRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($diskRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                $relative = $root . '/' . ltrim(str_replace($diskRoot, '', $fileInfo->getPathname()), '/');
                $candidateOriginal = preg_replace('/\.\d+x\d+\.webp$/', '', $relative);
                if (!$knownBasePaths->has($candidateOriginal)) {
                    $report['filesystem_orphan_files'][] = $relative;
                }
            }
        }

        return $report;
    }
}
