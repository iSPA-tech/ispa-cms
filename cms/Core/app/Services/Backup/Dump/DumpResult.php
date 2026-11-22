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
 * Immutable outcome of a verified database dump.
 */
final class DumpResult
{
    /**
     * @param string $format 'sql.gz' | 'pgdump' | 'sqlite.gz'
     * @param int $tablesExpected tables the live database reported
     * @param int $tablesDumped tables found in the dump
     * @param int $sizeBytes size of the target file
     * @param string $sha256 SHA-256 hex of the target file bytes
     */
    public function __construct(
        public readonly string $format,
        public readonly int $tablesExpected,
        public readonly int $tablesDumped,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
