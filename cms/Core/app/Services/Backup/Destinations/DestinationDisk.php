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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a throw-on-error filesystem for a backup destination.
 */
class DestinationDisk
{
    /**
     * Build the filesystem for a destination.
     *
     * @throws \InvalidArgumentException When the driver is unsupported or the config is refused
     */
    public static function for(BackupDestination $destination, ?DestinationRegistry $registry = null): Filesystem
    {
        $driver = ($registry ?? new DestinationRegistry())->get((string) $destination->driver);

        return Storage::build($driver->diskConfig($destination) + ['throw' => true]);
    }
}
