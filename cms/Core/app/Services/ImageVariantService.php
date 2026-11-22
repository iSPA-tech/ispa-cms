<?php

namespace Cms\Core\Services;

use Cms\Core\Models\Upload;
use Cms\Core\Models\UploadR2Object;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

class ImageVariantService
{
    /**
     * R2-ledger replication memo, relative_path => replicated?. The service is
     * container-scoped, so this lives for one request (or one queue job) —
     * serializing a gallery no longer asks the ledger once per preset path.
     *
     * @var array<string, bool>
     */
    private array $replicatedPathMemo = [];

    private const DEFAULT_PRESETS = [
        'sm' => ['width' => 300, 'height' => 0, 'mode' => 'scale'],
        'md' => ['width' => 768, 'height' => 0, 'mode' => 'scale'],
        'lg' => ['width' => 1200, 'height' => 0, 'mode' => 'scale'],
        'xl' => ['width' => 1440, 'height' => 0, 'mode' => 'scale'],
        'xxl' => ['width' => 2000, 'height' => 0, 'mode' => 'scale'],
        'thumbnail_sm' => ['width' => 80, 'height' => 80, 'mode' => 'cover'],
        'thumbnail_md' => ['width' => 300, 'height' => 300, 'mode' => 'cover'],
        'thumbnail_lg' => ['width' => 600, 'height' => 600, 'mode' => 'cover'],
        'thumbnail_xl' => ['width' => 1000, 'height' => 1000, 'mode' => 'cover'],
        'cover_sm' => ['width' => 160, 'height' => 96, 'mode' => 'cover'],
        'cover_md' => ['width' => 300, 'height' => 180, 'mode' => 'cover'],
        'cover_card' => ['width' => 480, 'height' => 288, 'mode' => 'cover'],
        'cover_lg' => ['width' => 600, 'height' => 360, 'mode' => 'cover'],
        'cover_xl' => ['width' => 1200, 'height' => 720, 'mode' => 'cover'],
        'facebook' => ['width' => 1200, 'height' => 630, 'mode' => 'cover'],
        'twitter' => ['width' => 1024, 'height' => 512, 'mode' => 'cover'],
    ];

    private const DEFAULT_CONTEXTS = [
        'media.grid' => ['thumbnail_sm', 'cover_sm'],
        // Vehicle listing card (112x70 CSS, so 224x140 at 2x). cover_sm alone
        // is what `media.grid` leaves behind and it goes soft on retina.
        'vehicle.card' => ['cover_sm', 'cover_md'],
        'avatar' => ['thumbnail_sm', 'thumbnail_md'],
        'post.card' => ['cover_card', 'cover_lg'],
        'post.detail' => ['cover_xl'],
        'post.cover' => ['cover_lg', 'cover_xl', 'facebook', 'twitter'],
        'post.content' => ['md', 'lg', 'xl'],
        'page.content' => ['md', 'lg', 'xl'],
        'comment.image' => ['sm', 'md'],
        'gallery.cover' => ['cover_lg', 'cover_xl', 'facebook', 'twitter'],
        'gallery.item' => ['md', 'lg', 'xl'],
        'banner' => ['md', 'lg', 'xl'],
        'real_estate.asset' => ['thumbnail_md', 'thumbnail_lg', 'thumbnail_xl', 'md', 'lg', 'xl'],
    ];

    public function url(Upload $upload, string $presetName, bool $generate = false): string
    {
        if (!$this->supports($upload) || !$this->preset($presetName)) {
            return $this->originalUrl($upload);
        }

        $existingUrl = $this->existingVariantUrl($upload, $presetName);
        if ($existingUrl) {
            return $existingUrl;
        }

        if ($generate) {
            $variant = $this->ensureVariant($upload, $presetName);
            $path = $variant['path'] ?? null;

            if ($path && Storage::disk($this->disk($upload))->exists($path)) {
                return $this->assetUrl($upload, $path);
            }
        }

        foreach ($this->fallbackPresetNames($presetName) as $fallbackPresetName) {
            $fallbackUrl = $this->existingVariantUrl($upload, $fallbackPresetName);
            if ($fallbackUrl) {
                return $fallbackUrl;
            }
        }

        return $this->originalUrl($upload);
    }

    /**
     * Return only the requested derivative when its bytes are available.
     * Unlike url(), this never falls back to another preset or the original,
     * so responsive markup cannot attach an incorrect width descriptor.
     */
    public function readyUrl(Upload $upload, string $presetName): ?string
    {
        if (! $this->supports($upload) || ! $this->preset($presetName)) {
            return null;
        }

        return $this->existingVariantUrl($upload, $presetName);
    }

    public function urlForContext(Upload $upload, string $context, string $presetName, bool $generate = true): string
    {
        if (!in_array($presetName, $this->contextPresets($context), true)) {
            return $this->originalUrl($upload);
        }

        return $this->url($upload, $presetName, $generate);
    }

    public function ensureContext(Upload $upload, string $context): array
    {
        $variants = [];

        foreach ($this->contextPresets($context) as $presetName) {
            $variants[$presetName] = $this->ensureVariant($upload, $presetName);
        }

        return Arr::where($variants, static fn($variant) => is_array($variant));
    }

    /**
     * @param bool $force Re-encode over an existing derivative instead of
     *                    leaving it alone. Overwrites in place — deleting
     *                    first would leave the URL 404ing for the length of an
     *                    encode (~200 ms per file), which on a site without a
     *                    CDN in front is a real broken image for whoever asks
     *                    during the window, and loses the old file outright if
     *                    the encode then fails.
     */
    public function ensureVariant(Upload $upload, string $presetName, bool $force = false): ?array
    {
        if (!$this->supports($upload) || !$this->preset($presetName)) {
            return null;
        }

        $lockPrefix = trim((string) config('ispa-cms.upload.orphan_lock_prefix', ''));
        if ($lockPrefix !== '' && $upload->exists) {
            $lock = Cache::lock($lockPrefix . ':orphan-path:' . sha1($upload->getTargetFile()), 300);
            if (! $lock->get()) {
                return null;
            }
            try {
                if (! Upload::query()->whereKey($upload->getKey())->exists()) {
                    return null;
                }

                return $this->ensureVariantUnlocked($upload, $presetName, $force);
            } finally {
                $lock->release();
            }
        }

        return $this->ensureVariantUnlocked($upload, $presetName, $force);
    }

    private function ensureVariantUnlocked(Upload $upload, string $presetName, bool $force): ?array
    {

        $disk = $this->disk($upload);
        $sourcePath = $upload->getTargetFile();
        $variantPath = $this->variantPath($upload, $presetName);

        if (!$variantPath) {
            return $this->markFailed($upload, $presetName, $variantPath, 'source_missing');
        }

        if (!Storage::disk($disk)->exists($sourcePath) && !$this->restoreSourceFromR2($disk, $sourcePath)) {
            return $this->markFailed($upload, $presetName, $variantPath, 'source_missing');
        }

        if ($force || !Storage::disk($disk)->exists($variantPath)) {
            try {
                $this->generate($disk, $sourcePath, $variantPath, $presetName);
                app(UploadR2ReplicationHook::class)->afterLocalWrite($variantPath, $upload->id, $disk);
            } catch (Throwable $e) {
                Log::warning('Image variant generation failed', [
                    'upload_id' => $upload->id,
                    'preset' => $presetName,
                    'source' => $sourcePath,
                    'target' => $variantPath,
                    'error' => $e->getMessage(),
                ]);

                return $this->markFailed($upload, $presetName, $variantPath, $e->getMessage());
            }
        }

        if (!Storage::disk($disk)->exists($variantPath)) {
            return $this->markFailed($upload, $presetName, $variantPath, 'variant_missing');
        }

        $preset = $this->preset($presetName);

        return $this->writeManifest($upload, $presetName, [
            'status' => 'ready',
            'path' => $variantPath,
            'disk' => $disk,
            'width' => $preset['width'],
            'height' => $preset['height'],
            'mode' => $preset['mode'] ?? 'cover',
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function preset(string $presetName): ?array
    {
        $preset = data_get(config('core.image_variants.presets', self::DEFAULT_PRESETS), $presetName);

        if (!$preset) {
            return null;
        }

        return [
            'width' => (int) ($preset['width'] ?? 0),
            'height' => (int) ($preset['height'] ?? 0),
            'mode' => (string) ($preset['mode'] ?? 'cover'),
        ];
    }

    public function dimensions(string $presetName): array
    {
        $preset = $this->preset($presetName);

        if (!$preset) {
            return [0, 0];
        }

        return [$preset['width'], $preset['height']];
    }

    public function contextsForUpload(Upload $upload): array
    {
        if (!$this->supports($upload)) {
            return [];
        }

        $contexts = ['media.grid'];
        $type = strtolower((string) $upload->type);
        $folder = strtolower((string) $upload->target_folder);
        $category = strtolower((string) $upload->category);
        $targetType = strtolower((string) $upload->target_type);

        if ($type === 'avatar' || $folder === 'avatar') {
            $contexts[] = 'avatar';
        }

        if ($folder === 'banner') {
            $contexts[] = 'banner';
        }

        if (str_contains($folder, 'realestate') || str_contains($targetType, 'realestate')) {
            $contexts[] = 'real_estate.asset';
        }

        if ($type === 'page' || $folder === 'page') {
            $contexts[] = 'page.content';
        }

        if ($category === 'commentimages') {
            $contexts[] = 'comment.image';
        }

        return array_values(array_unique($contexts));
    }

    /** Public so callers can warm a whole context without hardcoding its presets. */
    public function contextPresets(string $context): array
    {
        return array_values(config("core.image_variants.contexts.{$context}", self::DEFAULT_CONTEXTS[$context] ?? []));
    }

    private function generate(string $disk, string $sourcePath, string $variantPath, string $presetName): void
    {
        $preset = $this->preset($presetName);

        if (!$preset) {
            return;
        }

        $bytes = Storage::disk($disk)->get($sourcePath);
        $drivers = $this->preferredImageDriver() === 'imagick' ? ['imagick', 'gd'] : ['gd'];

        foreach ($drivers as $driver) {
            try {
                $image = $this->imageManager($driver)->read($bytes);

                if (($preset['mode'] ?? 'cover') === 'scale' || $preset['height'] < 1) {
                    $image = $image->scale($preset['width']);
                } else {
                    $image = $image->cover($preset['width'], $preset['height']);
                }

                $this->stripMetadata($image);
                Storage::disk($disk)->put($variantPath, (string) $image->toWebp($this->quality($preset)));

                return;
            } catch (Throwable $exception) {
                if ($driver !== 'imagick') {
                    throw $exception;
                }

                Log::warning('Image variant Imagick failed; retrying with GD', [
                    'source' => $sourcePath,
                    'target' => $variantPath,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    protected function preferredImageDriver(): string
    {
        return extension_loaded('imagick') ? 'imagick' : 'gd';
    }

    protected function imageManager(string $driver): ImageManager
    {
        return new ImageManager($driver === 'imagick' ? new ImagickDriver() : new GdDriver());
    }

    /**
     * Drops EXIF/IPTC/XMP/8BIM/ICC from a derivative before it is encoded.
     *
     * Intervention's Imagick driver carries the source's profiles into every
     * output, and a press photo's metadata is a fixed cost that does not shrink
     * with the image: one measured article JPEG carried 21.9 KB of it, so its
     * 160x96 thumbnail weighed 16.4 KB — 13.3 KB of that metadata, for 15,360
     * pixels. It also explains why raising or lowering the webp quality barely
     * moved the file size.
     *
     * Measured on that image: 160x96 16,390 -> 3,048 bytes (-81%), 600x360
     * 37,280 -> 23,938 (-36%), with a mean absolute pixel error of 0.000000.
     *
     * The ICC profile is the one exception. Dropping it declares the pixels
     * sRGB, which is free for an sRGB source and wrong for a wide-gamut one —
     * a Display P3 or Adobe RGB image read as sRGB renders visibly flat.
     * Reading real profile names across the fleet found both: 21 sRGB and 11
     * compact-sRGB against 2 Adobe98 and 1 Display P3 on one production API host, and
     * 11 non-sRGB in 60 on a second production site. So sRGB profiles go (a browser assumes
     * sRGB anyway) and anything else — including a profile we cannot name —
     * stays.
     */
    public function stripMetadata(ImageInterface $image): void
    {
        $native = $image->core()->native();

        // GD keeps no profiles in the first place, so this is Imagick-only.
        if (! $native instanceof \Imagick) {
            return;
        }

        $icc = $native->getImageProfiles('icc', true)['icc'] ?? null;

        $native->stripImage();

        //  setImageProfile, not profileImage: the latter asks lcms to convert
        //  the pixels through the profile. Nothing about the colours changed
        //  here, so the original tag goes back on verbatim.
        if (is_string($icc) && $icc !== '' && ! $this->isSrgbProfile($icc)) {
            $native->setImageProfile('icc', $icc);
        }
    }

    /**
     * True when an ICC profile describes plain sRGB, so dropping it changes
     * nothing a browser would render differently.
     *
     * Unreadable profiles deliberately answer false: keeping a few KB is the
     * cheap mistake, silently desaturating someone's photo is not.
     */
    private function isSrgbProfile(string $profile): bool
    {
        $description = $this->iccDescription($profile);

        if ($description === null) {
            return false;
        }

        //  "c2" is the description carried by the compact sRGB profile that
        //  Skia and friends emit; it is sRGB under a terser name.
        return (bool) preg_match('/^(sRGB\b|c2$)/i', trim($description));
    }

    /** Reads the `desc` tag out of an ICC profile, v2 and v4 layouts. */
    private function iccDescription(string $profile): ?string
    {
        if (strlen($profile) < 132) {
            return null;
        }

        $count = unpack('N', substr($profile, 128, 4))[1] ?? 0;
        if ($count < 1 || $count > 1024) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($profile, 132 + $i * 12, 12);
            if (strlen($entry) < 12 || substr($entry, 0, 4) !== 'desc') {
                continue;
            }

            $offset = unpack('N', substr($entry, 4, 4))[1];
            $size = unpack('N', substr($entry, 8, 4))[1];
            $tag = substr($profile, $offset, $size);

            //  v2 textDescriptionType: 4B sig, 4B reserved, 4B length, then
            //  the ASCII name (null-terminated).
            if (str_starts_with($tag, 'desc')) {
                $length = unpack('N', substr($tag, 8, 4))[1] ?? 0;

                return $length > 0 ? rtrim(substr($tag, 12, $length), "\0") : null;
            }

            //  v4 multiLocalizedUnicodeType: UTF-16BE run after the record
            //  table; stripping the null bytes is enough to name it.
            if (str_starts_with($tag, 'mluc')) {
                return trim(str_replace("\0", '', substr($tag, 28))) ?: null;
            }

            return null;
        }

        return null;
    }

    /**
     * generate() only ever reads its source from local disk. If the local original was evicted
     * after hash verification and retention (upload:r2-evict-local),
     * every not-yet-generated preset for that upload would otherwise become permanently
     * ungeneratable. Restore a working local copy from R2 first — only for a path this app
     * itself already confirmed synced/verified, never an arbitrary R2 key.
     */
    private function restoreSourceFromR2(string $disk, string $sourcePath): bool
    {
        $object = UploadR2Object::where('relative_path', $sourcePath)
            ->whereIn('status', ['synced', 'verified'])
            ->first();

        if (!$object) {
            return false;
        }

        try {
            Storage::disk($disk)->put($sourcePath, Storage::disk('r2')->get($sourcePath));
            return true;
        } catch (Throwable $e) {
            Log::warning('ImageVariantService: failed to restore source from R2', [
                'relative_path' => $sourcePath, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function variantPath(Upload $upload, string $presetName): ?string
    {
        $preset = $this->preset($presetName);

        if (!$preset) {
            return null;
        }

        return $upload->getTargetFile() . '.' . $preset['width'] . 'x' . $preset['height'] . '.webp';
    }

    private function existingVariantUrl(Upload $upload, string $presetName): ?string
    {
        $disk = $this->disk($upload);
        $servedFromR2 = $upload->servedFromR2();

        $manifest = data_get($upload->config, "variants.{$presetName}");
        $manifestPath = is_array($manifest) ? ($manifest['path'] ?? null) : null;

        if ($manifestPath) {
            // A ready manifest proves generation, not R2 replication. Serve a
            // fresh local derivative locally until the R2 ledger confirms the
            // key; otherwise the CDN returns 404 during the replication gap.
            $available = Storage::disk($disk)->exists($manifestPath)
                || ($servedFromR2 && $this->isReplicatedToR2($manifestPath));

            if ($available) {
                return $this->assetUrl($upload, $manifestPath);
            }
        }

        $legacyPath = $this->variantPath($upload, $presetName);
        if ($legacyPath) {
            // Pre-manifest uploads: the conventional derivative path. Prove it
            // by local existence, or — once evicted but served from R2 — by the
            // R2 ledger, so the thumbnail keeps resolving to the CDN instead of
            // silently degrading to the full-size original.
            $available = Storage::disk($disk)->exists($legacyPath)
                || ($servedFromR2 && $this->isReplicatedToR2($legacyPath));

            if ($available) {
                return $this->assetUrl($upload, $legacyPath);
            }
        }

        return null;
    }

    /**
     * True when a storage-relative path is tracked in the R2 ledger as synced
     * or verified — proof that an evicted local derivative still lives on R2.
     */
    private function isReplicatedToR2(string $relativePath): bool
    {
        return $this->replicatedPathMemo[$relativePath] ??= UploadR2Object::query()
            ->where('relative_path', $relativePath)
            ->whereIn('status', ['synced', 'verified'])
            ->exists();
    }

    /**
     * Answer every possible isReplicatedToR2() probe for a batch of uploads
     * with ONE ledger query. Candidate paths are deterministic (target file ×
     * configured presets), so a caller that is about to serialize a gallery
     * can warm the memo up front instead of paying a query per preset path.
     *
     * @param iterable<int|string, mixed> $uploads
     */
    public function warmReplicationLedger(iterable $uploads): void
    {
        $paths = [];

        foreach ($uploads as $upload) {
            if (! $upload instanceof Upload || ! $upload->servedFromR2() || ! $this->supports($upload)) {
                continue;
            }

            foreach (array_keys((array) config('core.image_variants.presets', self::DEFAULT_PRESETS)) as $presetName) {
                $path = $this->variantPath($upload, (string) $presetName);
                if ($path !== null && ! array_key_exists($path, $this->replicatedPathMemo)) {
                    $paths[$path] = true;
                }
            }
        }

        if ($paths === []) {
            return;
        }

        $replicated = UploadR2Object::query()
            ->whereIn('relative_path', array_keys($paths))
            ->whereIn('status', ['synced', 'verified'])
            ->pluck('relative_path')
            ->all();

        foreach (array_keys($paths) as $path) {
            $this->replicatedPathMemo[$path] = false;
        }
        foreach ($replicated as $path) {
            $this->replicatedPathMemo[(string) $path] = true;
        }
    }

    private function fallbackPresetNames(string $presetName): array
    {
        return match ($presetName) {
            'thumbnail_sm' => ['thumbnail_md', 'thumbnail_lg', 'thumbnail_xl'],
            'thumbnail_md' => ['thumbnail_lg', 'thumbnail_xl', 'thumbnail_sm'],
            'thumbnail_lg' => ['thumbnail_xl', 'thumbnail_md', 'thumbnail_sm'],
            'thumbnail_xl' => ['thumbnail_lg', 'thumbnail_md', 'thumbnail_sm'],
            'cover_sm' => ['cover_md', 'cover_lg', 'cover_xl'],
            'cover_md' => ['cover_lg', 'cover_xl', 'cover_sm'],
            'cover_card' => ['cover_lg', 'cover_md', 'cover_xl', 'cover_sm'],
            'cover_lg' => ['cover_xl', 'cover_md', 'cover_sm'],
            'cover_xl' => ['cover_lg', 'cover_md', 'cover_sm'],
            'sm' => ['md', 'lg', 'xl', 'xxl'],
            'md' => ['lg', 'xl', 'sm', 'xxl'],
            'lg' => ['xl', 'md', 'xxl', 'sm'],
            'xl' => ['lg', 'xxl', 'md', 'sm'],
            'xxl' => ['xl', 'lg', 'md', 'sm'],
            'facebook', 'twitter' => ['cover_xl', 'cover_lg'],
            default => [],
        };
    }

    private function supports(Upload $upload): bool
    {
        return in_array($upload->type, ['image', 'avatar'], true)
            || str_starts_with((string) $upload->mime_type, 'image/');
    }

    private function disk(Upload $upload): string
    {
        return $upload->disk ?: 'public';
    }

    private function originalUrl(Upload $upload): string
    {
        return $upload->publicUrlFor($upload->getTargetFile());
    }

    private function assetUrl(Upload $upload, string $path): string
    {
        if ($upload->servedFromR2() && $this->isReplicatedToR2($path)) {
            return $upload->publicUrlFor($path);
        }

        return asset('/storage/' . ltrim($path, '/'));
    }

    private function quality(array $preset): int
    {
        return ($preset['width'] <= 300 && $preset['height'] <= 300) ? 50 : 75;
    }

    private function markFailed(Upload $upload, string $presetName, ?string $path, string $reason): ?array
    {
        if (!$path) {
            return null;
        }

        return $this->writeManifest($upload, $presetName, [
            'status' => 'failed',
            'path' => $path,
            'disk' => $this->disk($upload),
            'reason' => $reason,
            'failed_at' => now()->toISOString(),
        ]);
    }

    /**
     * Deletes a derivative and forgets its manifest entry, so the next
     * ensureVariant() re-encodes it from the original.
     *
     * Only useful when the encoder itself changed — the metadata strip is the
     * live case. Note the variant path does not change, so a CDN that already
     * cached the old bytes under `immutable` keeps serving them until it is
     * purged; regenerating alone is not enough to make the smaller file
     * visible to visitors.
     */
    public function forget(Upload $upload, string $presetName): bool
    {
        $path = $this->variantPath($upload, $presetName);
        if (! $path) {
            return false;
        }

        $disk = $this->disk($upload);
        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }

        $config = $upload->config ?: [];
        if (isset($config['variants'][$presetName])) {
            unset($config['variants'][$presetName]);
            $upload->config = $config;
            if ($upload->exists) {
                $upload->saveQuietly();
            }
        }

        return true;
    }

    private function writeManifest(Upload $upload, string $presetName, array $variant): array
    {
        $config = $upload->config ?: [];
        $config['variants'] ??= [];
        $config['variants'][$presetName] = $variant;
        $upload->config = $config;

        if ($upload->exists) {
            $upload->saveQuietly();
        }

        return $variant;
    }
}
