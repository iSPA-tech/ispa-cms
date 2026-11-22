<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

use InvalidArgumentException;

/**
 * Maps driver keys to destination drivers. Later drivers register themselves with {@see register()}.
 */
class DestinationRegistry
{
    /** @var array<string, DestinationDriver> */
    private array $drivers = [];

    /**
     * Create a registry holding the built-in drivers (s3, local, sftp, webdav, gdrive, onedrive).
     */
    public function __construct()
    {
        $this->register(new S3Driver());
        $this->register(new LocalDriver());
        $this->register(new SftpDriver());
        $this->register(new WebdavDriver());
        $this->register(new GoogleDriveDriver());
        $this->register(new OneDriveDriver());
    }

    /**
     * Register (or replace) a driver under its own key.
     */
    public function register(DestinationDriver $driver): static
    {
        $this->drivers[$driver->key()] = $driver;

        return $this;
    }

    /**
     * Whether a driver key is supported.
     */
    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * Registered driver keys in registration order.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Get a driver by key.
     *
     * @throws InvalidArgumentException BACKUP_DRIVER_UNSUPPORTED when the key is unknown
     */
    public function get(string $key): DestinationDriver
    {
        return $this->drivers[$key] ?? throw new InvalidArgumentException('BACKUP_DRIVER_UNSUPPORTED');
    }
}
