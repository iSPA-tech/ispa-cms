<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Throwable;

/**
 * Minimal Flysystem adapter for Google Drive v3 on Laravel's Http client (no Google SDK).
 *
 * Paths are mapped to Drive folders under the app-created root `iSPA backups`; the object key `<prefix>/<site>/<date>/...` supplies the site folder. The OAuth scope is `drive.file`,
 * so only folders and files created by this app are visible. Folder ids are cached per destination, so a folder is
 * created once. Bearer tokens come from {@see OAuthConnector}; a 401 refreshes the token once and retries, a second
 * 401 marks the destination for reconnect and fails with {@see OAuthConnector::RECONNECT}. Failures carry only
 * `BACKUP_GDRIVE_HTTP_<status>` reasons, never tokens or response bodies. Drive allows duplicate names; the key layer
 * ({@see ObjectKey::fresh()}) avoids them, so `fileExists` simply reports whether the name exists.
 */
class GoogleDriveAdapter implements FilesystemAdapter
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const API = 'https://www.googleapis.com/drive/v3/files';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const FOLDER_CACHE_TTL = 86400;

    /** Default upload chunk size (8 MiB, a multiple of 256 KiB as Drive requires). */
    public const DEFAULT_CHUNK = 8388608;

    /**
     * Create an adapter for a destination.
     *
     * @param int $chunkSize Upload chunk size in bytes (tests use small values)
     */
    public function __construct(
        private readonly BackupDestination $destination,
        private readonly OAuthConnector $connector,
        private readonly int $chunkSize = self::DEFAULT_CHUNK,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        try {
            return $this->findFile($path) !== null;
        } catch (RuntimeException $e) {
            throw $this->passThrough($e) ?? UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        return $path === '' || $this->resolveFolder($this->segments($path), false) !== null;
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $segments = $this->segments($path);
        $name = array_pop($segments);
        $parent = $this->resolveFolder($segments, true);

        $init = $this->send(fn (PendingRequest $http) => $http->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream'])
            ->asJson()->timeout(30)->post(self::UPLOAD . '?uploadType=resumable', ['name' => $name, 'parents' => [$parent]]));
        $session = $init->header('Location');
        if (!$init->successful() || $session === '') {
            throw UnableToWriteFile::atLocation($path, $this->reason($init));
        }

        $buffer = $this->readChunk($contents);
        $next = $this->readChunk($contents);
        $position = 0;

        while (true) {
            $final = $next === '';
            $length = strlen($buffer);
            $range = $length === 0
                ? 'bytes */0'
                : 'bytes ' . $position . '-' . ($position + $length - 1) . '/' . ($final ? (string) ($position + $length) : '*');

            $response = $this->send(fn (PendingRequest $http) => $http->withBody($buffer, 'application/octet-stream')
                ->withHeaders(['Content-Range' => $range])->withoutRedirecting()->timeout(300)->put($session));

            if ($response->status() === 200 || $response->status() === 201) {
                return;
            }
            if ($response->status() !== 308) {
                throw UnableToWriteFile::atLocation($path, $this->reason($response));
            }

            // Resume from the byte Drive acknowledged (no Range header means nothing was stored yet).
            $acknowledged = 0;
            if (preg_match('/^bytes=0-(\d+)$/', $response->header('Range'), $m) === 1) {
                $acknowledged = (int) $m[1] + 1;
            }
            if ($acknowledged < $position || $acknowledged > $position + $length) {
                throw UnableToWriteFile::atLocation($path, 'BACKUP_GDRIVE_RESUME_MISMATCH');
            }
            $buffer = (string) substr($buffer, $acknowledged - $position);
            $position = $acknowledged;
            if ($buffer === '') {
                if ($final) {
                    throw UnableToWriteFile::atLocation($path, 'BACKUP_GDRIVE_UPLOAD_INCOMPLETE');
                }
                $buffer = $next;
                $next = $this->readChunk($contents);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $data = stream_get_contents($stream);
        fclose($stream);

        return $data === false ? throw UnableToReadFile::fromLocation($path, 'BACKUP_GDRIVE_READ_FAILED') : $data;
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        $file = $this->findFile($path) ?? throw UnableToReadFile::fromLocation($path, 'BACKUP_GDRIVE_NOT_FOUND');
        $response = $this->send(fn (PendingRequest $http) => $http->withOptions(['stream' => true])->timeout(300)
            ->get(self::API . '/' . rawurlencode((string) $file['id']) . '?alt=media'));
        if (!$response->successful()) {
            throw UnableToReadFile::fromLocation($path, $this->reason($response));
        }
        $handle = $response->toPsrResponse()->getBody()->detach();

        return $handle ?? throw UnableToReadFile::fromLocation($path, 'BACKUP_GDRIVE_READ_FAILED');
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $file = $this->findFile($path);
        if ($file === null) {
            return;
        }
        $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->delete(self::API . '/' . rawurlencode((string) $file['id'])));
        if (!$response->successful() && $response->status() !== 404) {
            throw UnableToDeleteFile::atLocation($path, $this->reason($response));
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'BACKUP_GDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'BACKUP_GDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'BACKUP_GDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'BACKUP_GDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'BACKUP_GDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        $file = $this->findFile($path);
        $time = $file !== null ? strtotime((string) ($file['modifiedTime'] ?? '')) : false;
        if ($time === false) {
            throw UnableToRetrieveMetadata::lastModified($path, 'BACKUP_GDRIVE_NOT_FOUND');
        }

        return new FileAttributes($path, null, null, $time);
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        $file = $this->findFile($path);
        if ($file === null || !isset($file['size'])) {
            throw UnableToRetrieveMetadata::fileSize($path, 'BACKUP_GDRIVE_NOT_FOUND');
        }

        return new FileAttributes($path, (int) $file['size']);
    }

    /**
     * @inheritDoc
     *
     * Only one folder level is listed; `$deep` is ignored.
     */
    public function listContents(string $path, bool $deep): iterable
    {
        return $this->listFolder(trim($path, '/'));
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    /**
     * Lazily list the direct children of a folder (nothing when the folder does not exist).
     *
     * @return Generator<int, FileAttributes|DirectoryAttributes>
     */
    private function listFolder(string $path): Generator
    {
        $folder = $this->resolveFolder($this->segments($path, true), false);
        if ($folder === null) {
            return;
        }

        $pageToken = null;
        do {
            $query = ['q' => "'" . $this->escape($folder) . "' in parents and trashed=false", 'fields' => 'nextPageToken,files(id,name,size,modifiedTime,mimeType)', 'pageSize' => 1000];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->get(self::API, $query));
            if (!$response->successful()) {
                throw UnableToListContents::atLocation($path, false, new RuntimeException($this->reason($response)));
            }
            foreach ((array) $response->json('files', []) as $item) {
                $itemPath = ($path === '' ? '' : $path . '/') . (string) $item['name'];
                yield ($item['mimeType'] ?? '') === self::FOLDER_MIME
                    ? new DirectoryAttributes($itemPath)
                    : new FileAttributes($itemPath, isset($item['size']) ? (int) $item['size'] : null, null, isset($item['modifiedTime']) ? (strtotime((string) $item['modifiedTime']) ?: null) : null);
            }
            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');
    }

    /**
     * Find a file by path (first match); null when its folder or the file does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function findFile(string $path): ?array
    {
        $segments = $this->segments($path);
        $name = array_pop($segments);
        $parent = $this->resolveFolder($segments, false);
        if ($parent === null) {
            return null;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->get(self::API, [
            'q' => "name='" . $this->escape($name) . "' and '" . $this->escape($parent) . "' in parents and trashed=false",
            'fields' => 'files(id,name,size,modifiedTime,mimeType)',
            'pageSize' => 1,
        ]));
        if (!$response->successful()) {
            throw new RuntimeException($this->reason($response));
        }

        return $response->json('files.0');
    }

    /**
     * Resolve (and optionally create) the folder id for the root folders plus the given segments.
     *
     * @param array<int, string> $segments
     */
    private function resolveFolder(array $segments, bool $create): ?string
    {
        $id = 'root';
        $trail = '';
        foreach (array_merge(['iSPA backups'], $segments) as $name) {
            if ($name === '') {
                continue;
            }
            $trail .= '/' . $name;
            $key = 'core:backup:gdrive:folder:' . $this->destination->uuid . ':' . sha1($trail);
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                $id = $cached;
                continue;
            }

            $found = $this->findFolder($name, $id);
            if ($found === null) {
                if (!$create) {
                    return null;
                }
                $found = $this->createFolder($name, $id);
            }
            Cache::put($key, $found, self::FOLDER_CACHE_TTL);
            $id = $found;
        }

        return $id;
    }

    /**
     * Find a child folder by name.
     */
    private function findFolder(string $name, string $parent): ?string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->get(self::API, [
            'q' => "name='" . $this->escape($name) . "' and '" . $this->escape($parent) . "' in parents and mimeType='" . self::FOLDER_MIME . "' and trashed=false",
            'fields' => 'files(id)',
            'pageSize' => 1,
        ]));
        if (!$response->successful()) {
            throw new RuntimeException($this->reason($response));
        }
        $id = $response->json('files.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Create a child folder and return its id.
     */
    private function createFolder(string $name, string $parent): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->asJson()->timeout(30)
            ->post(self::API . '?fields=id', ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parent]]));
        $id = $response->successful() ? $response->json('id') : null;
        if (!is_string($id) || $id === '') {
            throw UnableToCreateDirectory::atLocation($name, $this->reason($response));
        }

        return $id;
    }

    /**
     * Send a request with the destination's bearer token; refresh and retry once on 401, then require a reconnect.
     *
     * @param callable(PendingRequest): Response $call
     *
     * @throws RuntimeException BACKUP_OAUTH_RECONNECT (also from the connector)
     */
    private function send(callable $call): Response
    {
        $response = $call(Http::withToken($this->connector->accessToken($this->destination)));
        if ($response->status() !== 401) {
            return $response;
        }

        $this->connector->forgetAccessToken($this->destination);
        $response = $call(Http::withToken($this->connector->accessToken($this->destination)));
        if ($response->status() !== 401) {
            return $response;
        }

        $this->connector->forgetAccessToken($this->destination);
        $this->destination->forceFill(['last_error_code' => OAuthConnector::RECONNECT])->save();

        throw new RuntimeException(OAuthConnector::RECONNECT);
    }

    /**
     * Read up to one chunk from a stream (empty string at end of stream).
     *
     * @param resource $stream
     */
    private function readChunk($stream): string
    {
        $data = '';
        while (strlen($data) < $this->chunkSize && !feof($stream)) {
            $piece = fread($stream, $this->chunkSize - strlen($data));
            if ($piece === false) {
                break;
            }
            $data .= $piece;
        }

        return $data;
    }

    /**
     * Split a path into non-empty segments; a file path needs at least one.
     *
     * @return array<int, string>
     */
    private function segments(string $path, bool $allowEmpty = false): array
    {
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p) => $p !== ''));
        if ($parts === [] && !$allowEmpty) {
            throw new RuntimeException('BACKUP_GDRIVE_PATH_INVALID');
        }

        return $parts;
    }

    /**
     * Escape a value for a single-quoted Drive query string.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Error reason for a failed response (status only, never the body).
     */
    private function reason(Response $response): string
    {
        return 'BACKUP_GDRIVE_HTTP_' . $response->status();
    }

    /**
     * Return the exception itself when it is the reconnect signal so it surfaces unchanged; null otherwise.
     */
    private function passThrough(Throwable $e): ?Throwable
    {
        return $e->getMessage() === OAuthConnector::RECONNECT ? $e : null;
    }
}
