<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Dump;

/**
 * Produces a verified, compressed dump of one database connection.
 *
 * Failures are reported as \RuntimeException whose message starts with a machine code
 * (BACKUP_TABLE_COUNT_MISMATCH, BACKUP_DUMP_INCOMPLETE, BACKUP_NO_SPACE, BACKUP_DUMP_FAILED) and never
 * contains a password.
 */
interface DatabaseDumper
{
    /**
     * Dump the connection's database into the target file (mode 0600). On any failure the partial target is deleted.
     *
     * @throws \RuntimeException message prefixed with the machine error code
     */
    public function dump(string $connection, string $targetFile): DumpResult;
}
