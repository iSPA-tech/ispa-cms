<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Media;

use App\Helpers\ImageHelper;
use Cms\Core\Models\Upload;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * `media_import`: download an image from an allowed host (SafeHttpFetcher) into the media library.
 *
 * Stored through ImageHelper::storeUploadedImageFromSource (the media library's own path: decode check, pixel limit,
 * de-duplication by source URL, R2 replication, grid variants). Types: JPEG, PNG, WebP only, judged by the bytes
 * (finfo), not by the server's Content-Type. Size limit: `max_bytes` (default and cap in
 * `core.workflow_nodes.fetch.settings.media`).
 *
 * Config: `url`, `alt`, `max_bytes`, `storeAs`. Result: {upload_id, uuid (null: uploads have no uuid column), url,
 * mime_type, bytes, duplicate}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Media
 * @since   2026-11-24
 */
class MediaImportRunner extends IntegrationRunner
{
    private const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    protected function type(): string
    {
        return 'media_import';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $url = $this->renderText($config['url'] ?? '', $context);
        $alt = mb_substr($this->renderText($config['alt'] ?? '', $context), 0, 255);
        $settings = (array) SafeHttpFetcher::settings('media', []);
        $cap = max(1024, (int) ($settings['max_bytes'] ?? 10 * 1024 * 1024));
        $maxBytes = max(1, min($cap, (int) ($config['max_bytes'] ?? $cap)));

        $response = app(SafeHttpFetcher::class)->fetch($url, [
            'headers' => ['Accept' => 'image/webp,image/png,image/jpeg;q=0.9'],
            'max_bytes' => $maxBytes,
        ]);
        if (!$response->successful()) {
            throw new IntegrationFailure("the image answered HTTP {$response->status}.");
        }
        $bytes = $response->body;
        if ($bytes === '') {
            throw new IntegrationFailure('the image is empty.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::MIME_TYPES[$mime])) {
            throw new IntegrationFailure('only JPEG, PNG and WebP images can be imported (got ' . ($mime ?: 'unknown') . ').');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wf-media-');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new IntegrationFailure('the image could not be saved for checking.');
        }

        try {
            $file = new UploadedFile($tmp, 'import.' . self::MIME_TYPES[$mime], $mime, null, true);
            $stored = ImageHelper::storeUploadedImageFromSource(
                $file,
                $response->url,
                null,
                (string) ($settings['disk'] ?? 'public'),
                null,
                ['workflow_import' => true],
                (int) ($settings['max_pixels'] ?? 40_000_000),
            );
        } catch (InvalidArgumentException $e) {
            throw new IntegrationFailure('the image was refused: ' . $e->getMessage());
        } finally {
            @unlink($tmp);
        }

        /** @var Upload $upload */
        $upload = $stored['upload'];
        if ($alt !== '' && !$stored['duplicate'] && trim((string) $upload->alt_text) === '') {
            $upload->forceFill(['alt_text' => $alt])->save();
        }

        $result = [
            'upload_id' => $upload->id,
            'uuid' => $upload->getAttribute('uuid'),
            'url' => $upload->url,
            'mime_type' => $mime,
            'bytes' => strlen($bytes),
            'duplicate' => (bool) $stored['duplicate'],
        ];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'media_import',
            'host' => $response->host(),
            'bytes' => strlen($bytes),
            'mime_type' => $mime,
            'duplicate' => (bool) $stored['duplicate'],
        ]]);
    }
}
