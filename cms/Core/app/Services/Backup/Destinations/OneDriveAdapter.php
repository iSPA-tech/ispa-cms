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
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
 * Minimal Flysystem adapter for OneDrive on Microsoft Graph and Laravel's Http client (no Microsoft SDK).
 *
 * Paths are the object key written directly under the app folder (`special/approot`); the OAuth scope
 * `Files.ReadWrite.AppFolder` only exposes that folder, and Graph creates missing parent folders on upload. Uploads use
 * a resumable upload session with sequential chunks (a multiple of 320 KiB as Graph requires). The session `uploadUrl`
 * is pre-authenticated, so no bearer token is sent to it. Bearer tokens come from {@see OAuthConnector}; a 401 refreshes
 * the token once and retries, a second 401 marks the destination for reconnect and fails with
 * {@see OAuthConnector::RECONNECT}. Failures carry only `BACKUP_ONEDRIVE_HTTP_<status>` reasons, never tokens or
 * response bodies.
 */
class OneDriveAdapter implements FilesystemAdapter
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const APPROOT = '/me/drive/special/approot';

    /** Default upload chunk size (10 MiB = 32 x 320 KiB, the granularity Graph requires). */
    public const DEFAULT_CHUNK = 10485760;

    /** Chunk granularity required by Graph upload sessions (320 KiB). */
    public const CHUNK_UNIT = 327680;

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
            $item = $this->findItem($path);

            return $item !== null && !isset($item['folder']);
        } catch (RuntimeException $e) {
            throw $this->passThrough($e) ?? UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        if (trim($path, '/') === '') {
            return true;
        }
        $item = $this->findItem($path);

        return $item !== null && isset($item['folder']);
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
        $url = $this->itemUrl($path);

        // Graph needs the total size up front; spool streams that cannot report it.
        $stat = fstat($contents);
        $total = is_array($stat) && ($stat['size'] ?? 0) > 0 ? (int) $stat['size'] : null;
        $spool = null;
        if ($total === null) {
            $spool = fopen('php://temp', 'w+b');
            stream_copy_to_stream($contents, $spool);
            rewind($spool);
            $total = (int) (fstat($spool)['size'] ?? 0);
            $contents = $spool;
        }

        try {
            if ($total === 0) {
                // Upload sessions cannot carry an empty file; a plain content PUT can.
                $response = $this->send(fn (PendingRequest $http) => $http->withBody('', 'application/octet-stream')->timeout(60)->put($url . ':/content'));
                if (!$response->successful()) {
                    throw UnableToWriteFile::atLocation($path, $this->reason($response));
                }

                return;
            }

            $init = $this->send(fn (PendingRequest $http) => $http->asJson()->timeout(30)
                ->post($url . ':/createUploadSession', ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]));
            $session = $init->successful() ? $init->json('uploadUrl') : null;
            if (!is_string($session) || $session === '') {
                throw UnableToWriteFile::atLocation($path, $init->successful() ? 'BACKUP_ONEDRIVE_SESSION_INVALID' : $this->reason($init));
            }

            $this->uploadChunks($path, $session, $contents, $total);
        } finally {
            if ($spool !== null) {
                fclose($spool);
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

        return $data === false ? throw UnableToReadFile::fromLocation($path, 'BACKUP_ONEDRIVE_READ_FAILED') : $data;
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        $response = $this->send(fn (PendingRequest $http) => $http->withOptions(['stream' => true])->timeout(300)
            ->get($this->itemUrl($path) . ':/content'));
        if (!$response->successful()) {
            throw UnableToReadFile::fromLocation($path, $this->reason($response));
        }
        try {
            $handle = StreamWrapper::getResource($response->toPsrResponse()->getBody());
        } catch (Throwable) {
            throw UnableToReadFile::fromLocation($path, 'BACKUP_ONEDRIVE_READ_FAILED');
        }

        return $handle;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->delete($this->itemUrl($path)));
        if (!$response->successful() && $response->status() !== 404) {
            throw UnableToDeleteFile::atLocation($path, $this->reason($response));
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'BACKUP_ONEDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'BACKUP_ONEDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'BACKUP_ONEDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'BACKUP_ONEDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'BACKUP_ONEDRIVE_UNSUPPORTED');
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        $item = $this->findItem($path);
        $time = $item !== null ? strtotime((string) ($item['lastModifiedDateTime'] ?? '')) : false;
        if ($time === false) {
            throw UnableToRetrieveMetadata::lastModified($path, 'BACKUP_ONEDRIVE_NOT_FOUND');
        }

        return new FileAttributes($path, null, null, $time);
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        $item = $this->findItem($path);
        if ($item === null || !isset($item['size'])) {
            throw UnableToRetrieveMetadata::fileSize($path, 'BACKUP_ONEDRIVE_NOT_FOUND');
        }

        return new FileAttributes($path, (int) $item['size']);
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
     * PUT the stream to the pre-authenticated session URL in sequential chunks, retrying a failed chunk once.
     *
     * @param resource $stream
     */
    private function uploadChunks(string $path, string $session, $stream, int $total): void
    {
        $position = 0;
        while ($position < $total) {
            $chunk = $this->readChunk($stream);
            $length = strlen($chunk);
            if ($length === 0) {
                throw UnableToWriteFile::atLocation($path, 'BACKUP_ONEDRIVE_UPLOAD_INCOMPLETE');
            }
            $range = 'bytes ' . $position . '-' . ($position + $length - 1) . '/' . $total;

            $response = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    // The uploadUrl carries its own credentials: never attach the bearer token.
                    $response = Http::withBody($chunk, 'application/octet-stream')
                        ->withHeaders(['Content-Range' => $range])->withoutRedirecting()->timeout(300)->put($session);
                } catch (ConnectionException) {
                    $response = null;
                    continue;
                }
                if ($response->status() < 500) {
                    break;
                }
            }
            if ($response === null) {
                throw UnableToWriteFile::atLocation($path, 'BACKUP_ONEDRIVE_CONNECTION_FAILED');
            }

            $position += $length;
            $status = $response->status();
            if ($status === 200 || $status === 201) {
                if ($position < $total) {
                    throw UnableToWriteFile::atLocation($path, 'BACKUP_ONEDRIVE_UPLOAD_MISMATCH');
                }

                return;
            }
            if ($status !== 202) {
                throw UnableToWriteFile::atLocation($path, $this->reason($response));
            }
        }

        throw UnableToWriteFile::atLocation($path, 'BACKUP_ONEDRIVE_UPLOAD_INCOMPLETE');
    }

    /**
     * Lazily list the direct children of a folder (nothing when the folder does not exist).
     *
     * @return Generator<int, FileAttributes|DirectoryAttributes>
     */
    private function listFolder(string $path): Generator
    {
        $url = $path === '' ? self::GRAPH . self::APPROOT . '/children' : $this->itemUrl($path) . ':/children';
        while ($url !== null) {
            $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->get($url));
            if ($response->status() === 404) {
                return;
            }
            if (!$response->successful()) {
                throw UnableToListContents::atLocation($path, false, new RuntimeException($this->reason($response)));
            }
            foreach ((array) $response->json('value', []) as $item) {
                $itemPath = ($path === '' ? '' : $path . '/') . (string) $item['name'];
                yield isset($item['folder'])
                    ? new DirectoryAttributes($itemPath)
                    : new FileAttributes($itemPath, isset($item['size']) ? (int) $item['size'] : null, null, isset($item['lastModifiedDateTime']) ? (strtotime((string) $item['lastModifiedDateTime']) ?: null) : null);
            }
            $next = ((array) $response->json())['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }
    }

    /**
     * Fetch an item's metadata by path; null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function findItem(string $path): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->timeout(30)->get($this->itemUrl($path)));
        if ($response->status() === 404) {
            return null;
        }
        if (!$response->successful()) {
            throw new RuntimeException($this->reason($response));
        }

        return (array) $response->json();
    }

    /**
     * Graph URL addressing an item under the app folder by path (without a trailing action).
     */
    private function itemUrl(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p) => $p !== ''));
        if ($parts === []) {
            throw new RuntimeException('BACKUP_ONEDRIVE_PATH_INVALID');
        }

        return self::GRAPH . self::APPROOT . ':/' . implode('/', array_map('rawurlencode', $parts));
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
     * Error reason for a failed response (status only, never the body).
     */
    private function reason(Response $response): string
    {
        return 'BACKUP_ONEDRIVE_HTTP_' . $response->status();
    }

    /**
     * Return the exception itself when it is the reconnect signal so it surfaces unchanged; null otherwise.
     */
    private function passThrough(Throwable $e): ?Throwable
    {
        return $e->getMessage() === OAuthConnector::RECONNECT ? $e : null;
    }
}
