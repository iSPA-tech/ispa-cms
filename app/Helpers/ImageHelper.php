<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

use Exception;
use GuzzleHttp\Client;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Services\UploadR2ReplicationHook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImageHelper
{
    /**
     * Get the image URL from the content
     *
     * @param string $content
     *
     * @return string
     * @since 7.160.1175 - 2024-07-21, 20:43 ICT
     */
    public static function getImageUrl(string $content): string
    {
        $pattern = /** @lang text */
            '/<img[^>]+src=[\'"](?P<src>[^\'"]+)[\'"][^>]*>/';
        preg_match($pattern, $content, $matches);

        return $matches['src'] ?? '';
    }

    /**
     * Download Image URL to local
     *
     * @param string|null $url
     * @param string|null $path
     * @param string $disk
     * @param array<int, string>|string|null $variantContexts
     *
     * @return Upload|null
     * @author malayvuong
     * @since 7.160.1175 - 2024-07-27, 16:52 ICT
     */
    public static function downloadImageFromUrl(
        string $url = null,
        string $path = null,
        string $disk = 'public',
        array|string|null $variantContexts = null
    ): ?Upload
    {
        if (!$url) {
            return null;
        }
        try {
            $downloadUrl = $url;
            $sourceUrlHash = self::sourceUrlHash($downloadUrl);
            $variantContexts = self::normalizeVariantContexts($variantContexts);

            return self::withSourceUrlLock($sourceUrlHash, function () use (
                $disk,
                $downloadUrl,
                $path,
                $sourceUrlHash,
                $url,
                $variantContexts,
            ): ?Upload {
                $existingImage = self::findDownloadedImage($sourceUrlHash, $disk);
                if ($existingImage) {
                    self::warmImageVariantContexts($existingImage, $variantContexts);

                    return self::prepareDownloadedImageResponse($existingImage);
                }

                $uploadFolder = $path ?: 'image/' . now()->format('Y/m');
                //  Make directory if not exists
                //  Explicit 0775 matches config/filesystems.php's public disk dir
                //  permission; File::ensureDirectoryExists()'s implicit 0755 default
                //  strips group-write, so any non-owner CLI process (php artisan
                //  tinker, queue workers under a different user) can't write into
                //  a directory this crawler path created.
                File::ensureDirectoryExists(Storage::disk($disk)->path($uploadFolder), 0775);
                // Keep signed query strings for CDNs that require them, but derive
                // the local filename from the clean path.
                $fileUrl = strtok($url, '?');

                //  Save image file into upload folder $uploadFolder
                $fileExtension = pathinfo($fileUrl, PATHINFO_EXTENSION);
                $fileName = str(basename($fileUrl))
                    ->replaceLast($fileExtension, '')
                    ->slug() . '-' . time();
                if (empty($fileExtension)) {
                    $fileExtension = 'jpg';
                }

                $fullFileName = $fileName . '.' . $fileExtension;
                $filePath = $uploadFolder . '/' . $fullFileName;

                $localPath = self::downloadImageWithHeaders($downloadUrl, Storage::disk($disk)->path($filePath));
                //  Begin download image
                if (!$localPath) {
                    return null;
                }

                //  Get file extension, size, mime type, and dimension
                $fileSize = Storage::fileSize($filePath);
                $fileMimeType = Storage::mimeType($filePath);
                $fileDimension = getimagesize(storage_path('app/public/' . $filePath));
                //  Save image into storage
                $data = [
                    'disk' => $disk,
                    'type' => 'image',
                    'name' => $fullFileName,
                    'path' => $uploadFolder,
                    'extension' => $fileExtension,
                    'file_size' => $fileSize,
                    'mime_type' => $fileMimeType,
                    'user_id' => auth()->id(),
                    'target_folder' => 'Post',
                    'category' => 'images',
                    'main' => false,
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
                        'source_url' => $downloadUrl,
                        'source_url_hash' => $sourceUrlHash,
                    ],
                ];

                $savedImage = new Upload($data);

                $savedImage->save();
                app(UploadR2ReplicationHook::class)->afterLocalWrite($filePath, $savedImage->id, $disk);
                self::warmImageVariantContexts($savedImage, $variantContexts);

                return self::prepareDownloadedImageResponse($savedImage);
            });
        } catch (GuzzleException|Exception $e) {
            logger($e->getMessage());
            return null;
        }
    }

    /**
     * Store image bytes already fetched by a trusted browser session.
     *
     * @param array<int, string>|string|null $variantContexts
     * @param array<string, mixed> $custom
     * @return array{upload: Upload, duplicate: bool}
     */
    public static function storeUploadedImageFromSource(
        UploadedFile $file,
        string $sourceUrl,
        string $path = null,
        string $disk = 'public',
        array|string|null $variantContexts = null,
        array $custom = [],
        ?int $maxPixels = null,
    ): array {
        $sourceUrlHash = self::sourceUrlHash($sourceUrl);
        $variantContexts = self::normalizeVariantContexts($variantContexts);

        return self::withSourceUrlLock($sourceUrlHash, function () use (
            $custom,
            $disk,
            $file,
            $maxPixels,
            $path,
            $sourceUrl,
            $sourceUrlHash,
            $variantContexts,
        ): array {
            $mimeType = (string) $file->getMimeType();
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw new InvalidArgumentException('Unsupported browser image MIME type.'),
            };
            $dimensions = @getimagesize($file->getPathname());
            if (
                ! is_array($dimensions)
                || (string) ($dimensions['mime'] ?? '') !== $mimeType
                || (int) ($dimensions[0] ?? 0) < 1
                || (int) ($dimensions[1] ?? 0) < 1
            ) {
                throw new InvalidArgumentException('Browser image payload is invalid.');
            }
            $pixelCount = (int) $dimensions[0] * (int) $dimensions[1];
            if ($maxPixels !== null && $pixelCount > $maxPixels) {
                throw new InvalidArgumentException('Browser image pixel count exceeds the limit.');
            }

            $bytes = file_get_contents($file->getPathname());
            if ($bytes === false || $bytes === '') {
                throw new InvalidArgumentException('Browser image payload is empty.');
            }
            if (! function_exists('imagecreatefromstring')) {
                throw new InvalidArgumentException('Browser image decoding is unavailable.');
            }
            $decoded = @imagecreatefromstring($bytes);
            if ($decoded === false) {
                throw new InvalidArgumentException('Browser image payload cannot be decoded.');
            }
            imagedestroy($decoded);

            $existingImage = self::findDownloadedImage($sourceUrlHash, $disk);

            if ($existingImage) {
                self::warmImageVariantContexts($existingImage, $variantContexts);

                return [
                    'upload' => self::prepareDownloadedImageResponse($existingImage),
                    'duplicate' => true,
                ];
            }

            $uploadFolder = $path ?: 'image/' . now()->format('Y/m');
            $sourcePath = (string) parse_url($sourceUrl, PHP_URL_PATH);
            $sourceName = pathinfo($sourcePath, PATHINFO_FILENAME);
            $slug = (string) str($sourceName ?: 'image')->slug()->limit(80, '');
            $fullFileName = ($slug !== '' ? $slug : 'image')
                . '-' . substr($sourceUrlHash, 0, 12)
                . '.' . $extension;
            $filePath = $uploadFolder . '/' . $fullFileName;

            if (! Storage::disk($disk)->put($filePath, $bytes)) {
                throw new RuntimeException('Could not store browser image payload.');
            }

            try {
                $savedImage = Upload::query()->create([
                    'disk' => $disk,
                    'type' => 'image',
                    'name' => $fullFileName,
                    'path' => $uploadFolder,
                    'extension' => $extension,
                    'file_size' => strlen($bytes),
                    'mime_type' => $mimeType,
                    'user_id' => auth()->id(),
                    'target_folder' => 'Post',
                    'category' => 'images',
                    'main' => false,
                    'dimensions' => [
                        'width' => $dimensions[0] ?? 0,
                        'height' => $dimensions[1] ?? 0,
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
                        ...$custom,
                        'source_url' => $sourceUrl,
                        'source_url_hash' => $sourceUrlHash,
                    ],
                ]);
            } catch (Throwable $exception) {
                Storage::disk($disk)->delete($filePath);

                throw $exception;
            }

            app(UploadR2ReplicationHook::class)->afterLocalWrite($filePath, $savedImage->id, $disk);
            self::warmImageVariantContexts($savedImage, $variantContexts);

            return [
                'upload' => self::prepareDownloadedImageResponse($savedImage),
                'duplicate' => false,
            ];
        });
    }

    private static function sourceUrlHash(string $url): string
    {
        return hash('sha256', self::normalizeSourceUrl($url));
    }

    private static function normalizeSourceUrl(string $url): string
    {
        return trim($url);
    }

    private static function withSourceUrlLock(string $sourceUrlHash, callable $callback): mixed
    {
        return Cache::lock("image:source-url:{$sourceUrlHash}", 300)
            ->block(30, $callback);
    }

    private static function findDownloadedImage(string $sourceUrlHash, string $disk): ?Upload
    {
        if ($sourceUrlHash === '') {
            return null;
        }

        try {
            $query = Upload::query()
                ->where('disk', $disk)
                ->where('type', 'image');

            if ($query->getConnection()->getDriverName() === 'pgsql') {
                $query->whereRaw("(custom::jsonb)->>'source_url_hash' = ?", [$sourceUrlHash]);
            } else {
                $query->where('custom->source_url_hash', $sourceUrlHash);
            }

            foreach ($query->latest('id')->cursor() as $upload) {
                if (self::isValidStoredImage($upload, $disk)) {
                    return $upload;
                }
            }

            return null;
        } catch (Throwable $exception) {
            Log::warning('Downloaded image lookup failed', [
                'source_url_hash' => $sourceUrlHash,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private static function isValidStoredImage(Upload $upload, string $fallbackDisk): bool
    {
        $disk = $upload->disk ?: $fallbackDisk;
        $storage = Storage::disk($disk);
        $target = $upload->getTargetFile();

        if (! $storage->exists($target) || (int) $storage->size($target) < 1) {
            return false;
        }

        try {
            $bytes = $storage->get($target);
        } catch (Throwable) {
            return false;
        }
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            return false;
        }
        imagedestroy($decoded);

        return true;
    }

    /**
     * @param array<int, string> $variantContexts
     */
    private static function warmImageVariantContexts(Upload $upload, array $variantContexts): void
    {
        $variantService = app(ImageVariantService::class);

        foreach ($variantContexts as $context) {
            try {
                $variantService->ensureContext($upload, $context);
            } catch (Throwable $exception) {
                Log::warning('Downloaded image variant warmup failed', [
                    'upload_id' => $upload->id,
                    'context' => $context,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private static function prepareDownloadedImageResponse(Upload $upload): Upload
    {
        $upload = $upload->fresh() ?? $upload;
        $upload->setAppends([
            'thumbnail_sm',
            'cover_sm',
            'sm',
            'cover_md',
            'cover_lg',
            'cover_xl',
            'url',
        ]);

        return $upload;
    }

    /**
     * Normalize requested warmup contexts while preserving the media grid default.
     *
     * @param array<int, string>|string|null $contexts
     * @return array<int, string>
     */
    private static function normalizeVariantContexts(array|string|null $contexts): array
    {
        if ($contexts === null) {
            $contexts = ['media.grid'];
        }

        if (is_string($contexts)) {
            $contexts = [$contexts];
        }

        $contexts = array_values(array_unique(array_filter(
            array_map(static fn($context) => trim((string) $context), $contexts),
            static fn(string $context) => $context !== ''
        )));

        return $contexts ?: ['media.grid'];
    }

    /** Redis cache TTL for Cloudflare-blocked domains (24 hours). */
    private const CF_BLOCK_TTL = 86400;

    /**
     * Check if a domain is known to be blocked by Cloudflare.
     */
    public static function isDomainBlocked(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host && Cache::has("img_cf_blocked:{$host}");
    }

    /**
     * Build a Guzzle client with modern browser-like headers.
     *
     * @param string $url The image URL (used to derive Referer)
     * @param bool $useSocks5 Whether to route through SOCKS5 proxy
     * @return Client
     */
    private static function buildImageClient(string $url, bool $useSocks5 = false): Client
    {
        $host = parse_url($url, PHP_URL_HOST);
        $referer = parse_url($url, PHP_URL_SCHEME) . '://' . $host . '/';

        $options = [
            'headers' => [
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => $referer,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ],
            'timeout' => $useSocks5 ? 45 : 30,
            'connect_timeout' => $useSocks5 ? 15 : 10,
        ];

        if ($useSocks5) {
            $options['proxy'] = (string) config('services.image_proxy.socks5', '');
        }

        return new Client($options);
    }

    /**
     * Download image from URL using Guzzle with modern browser-like headers.
     *
     * Strategy: try direct download first, if Cloudflare blocks it, automatically
     * retry through SOCKS5 VPN proxy. Domains confirmed blocked are cached in
     * Redis for 24h so future requests go through SOCKS5 immediately.
     *
     * @param string $url
     * @param string $savePath
     *
     * @return string File path on success, empty string on failure
     * @throws GuzzleException
     * @author malayvuong
     * @since 7.160.1175 - 2024-10-24, 21:03 ICT
     */
    public static function downloadImageWithHeaders(string $url, string $savePath): string
    {
        $candidates = self::buildImageDownloadCandidates($url);

        foreach ($candidates as $candidate) {
            $result = self::downloadSingleImageWithHeaders($candidate, $savePath);

            if ($result !== '') {
                if ($candidate !== $url) {
                    Log::info("ImageHelper: downloaded {$url} through fallback {$candidate}");
                }

                return $result;
            }

            @unlink($savePath);
        }

        return '';
    }

    /**
     * Download one image candidate using direct then SOCKS5 fallback.
     */
    private static function downloadSingleImageWithHeaders(string $url, string $savePath): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        // Domain already known to need SOCKS5: skip direct attempt
        if ($host && Cache::has("img_cf_blocked:{$host}")) {
            return self::downloadViaSocks5($url, $savePath);
        }

        // Attempt 1: direct download
        $result = self::attemptDownload($url, $savePath, useSocks5: false);

        if ($result === self::RETRY_VIA_SOCKS5) {
            // Cache domain so future requests skip direct attempt
            if ($host) {
                Cache::put("img_cf_blocked:{$host}", true, self::CF_BLOCK_TTL);
            }

            // Attempt 2: retry through SOCKS5 VPN proxy
            return self::downloadViaSocks5($url, $savePath);
        }

        return $result ?: '';
    }

    /**
     * Build ordered download candidates: the URL itself, then its WordPress Photon mirrors when it is listed.
     */
    private static function buildImageDownloadCandidates(string $url): array
    {
        $candidates = [$url];

        if (! self::usesPhotonMirrors($url)) {
            return $candidates;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (! $host || ! $path) {
            return $candidates;
        }

        foreach (['i0.wp.com', 'i1.wp.com', 'i2.wp.com'] as $mirrorHost) {
            $candidates[] = "https://{$mirrorHost}/{$host}{$path}?ssl=1";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * True when the image's "host/path" starts with a prefix in `services.image_proxy.photon_mirror_prefixes`
     * (sites that block direct media downloads while WordPress Photon mirrors them; modules add their own).
     */
    private static function usesPhotonMirrors(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($host) || ! is_string($path)) {
            return false;
        }

        foreach ((array) config('services.image_proxy.photon_mirror_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($host . $path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download image through SOCKS5 VPN proxy.
     *
     * @param string $url
     * @param string $savePath
     * @return string File path on success, empty string on failure
     */
    private static function downloadViaSocks5(string $url, string $savePath): string
    {
        if (! self::isSocks5Available()) {
            Log::warning("ImageHelper: SOCKS5 proxy not available, skipping {$url}");

            return '';
        }

        $result = self::attemptDownload($url, $savePath, useSocks5: true);

        if ($result && $result !== self::RETRY_VIA_SOCKS5) {
            return $result;
        }

        Log::warning("ImageHelper: SOCKS5 download also failed for {$url}");

        return '';
    }

    /** Signals that the direct attempt should be retried via SOCKS5. */
    private const RETRY_VIA_SOCKS5 = '__retry_socks5__';

    /**
     * Perform a single download attempt.
     *
     * @param string $url
     * @param string $savePath
     * @param bool $useSocks5
     * @return string File path on success, RETRY_VIA_SOCKS5 when fallback should be tried, empty string on permanent failure
     */
    private static function attemptDownload(string $url, string $savePath, bool $useSocks5 = false): string
    {
        $via = $useSocks5 ? 'SOCKS5' : 'direct';

        try {
            $client = self::buildImageClient($url, $useSocks5);

            $response = $client->get($url, [
                'sink' => $savePath,
                'verify' => false,
            ]);

            if ($response->getStatusCode() === 200) {
                $contentType = $response->getHeaderLine('Content-Type');
                if ($contentType && ! str_starts_with($contentType, 'image/')) {
                    Log::warning("ImageHelper[{$via}]: Expected image but got {$contentType} for {$url}");
                    @unlink($savePath);

                    return '';
                }

                return $savePath;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse()?->getStatusCode();

            // Any 403 on direct attempt → retry via SOCKS5 (Cloudflare, WAF, hotlink protection, etc.)
            if ($status === 403 && ! $useSocks5) {
                $body = (string) $e->getResponse()?->getBody();
                $reason = self::isCloudflareChallenge($body) ? 'Cloudflare' : 'HTTP 403';
                Log::info("ImageHelper[direct]: {$reason} on {$url}, will retry via SOCKS5");

                return self::RETRY_VIA_SOCKS5;
            }

            Log::warning("ImageHelper[{$via}]: HTTP {$status} downloading {$url}");
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $message = $e->getMessage();

            // DNS resolution failure on direct attempt → retry via SOCKS5
            // socks5h:// resolves DNS through the proxy, bypassing local DNS issues
            if (! $useSocks5 && str_contains($message, 'Could not resolve host')) {
                Log::info("ImageHelper[direct]: DNS failure for {$url}, will retry via SOCKS5");

                return self::RETRY_VIA_SOCKS5;
            }

            if ($useSocks5) {
                Log::warning("ImageHelper[SOCKS5]: Proxy connection failed for {$url}");
            } else {
                Log::warning("ImageHelper[direct]: Connection failed for {$url}");
            }
        } catch (Exception $e) {
            Log::error("ImageHelper[{$via}]: {$e->getMessage()} downloading {$url}");
        }

        return '';
    }

    /**
     * Check if SOCKS5 proxy is available (microsocks on VPN tunnel).
     */
    private static function isSocks5Available(): bool
    {
        $proxy = (string) config('services.image_proxy.socks5', '');
        if ($proxy === '') {
            return false; // no proxy configured (IMAGE_PROXY_SOCKS5)
        }
        $parsed = parse_url(str_replace('socks5h://', 'http://', $proxy));
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 1080;

        $conn = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($conn) {
            fclose($conn);

            return true;
        }

        return false;
    }

    /**
     * Detect Cloudflare challenge/block page from response body.
     */
    private static function isCloudflareChallenge(string $body): bool
    {
        $lower = strtolower(substr($body, 0, 8192));

        return str_contains($lower, 'cloudflare')
            && (str_contains($lower, 'attention required')
                || str_contains($lower, 'cf-chl')
                || str_contains($lower, 'just a moment')
                || str_contains($lower, 'enable javascript'));
    }
}
