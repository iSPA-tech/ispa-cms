<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

/**
 * Immutable configuration options for an import operation.
 *
 * Controls transaction mode, dry-run, chunk size, preview row limits,
 * error stop behavior, forced updates, and strict header validation.
 *
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
readonly class ImportOptions
{
    /**
     * Initialize import options with transaction mode, dry-run, chunk size, and validation flags.
     *
     * @param string   $transaction_mode   Transaction strategy: 'single', 'chunk', or 'none'.
     * @param bool     $dry_run            Whether to simulate without database writes.
     * @param int      $chunk_size         Number of rows per processing chunk.
     * @param int|null $limit_preview_rows Maximum rows to process (null for unlimited).
     * @param bool     $stop_on_error      Whether to halt on the first row error.
     * @param bool     $always_update      Whether to force updates on existing rows.
     * @param bool     $strict_headers     Whether to enforce strict header column validation.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public string $transaction_mode = 'single', // 'single', 'chunk', 'none'
        public bool $dry_run = false,
        public int $chunk_size = 1000,
        public ?int $limit_preview_rows = null,
        public bool $stop_on_error = false,
        public bool $always_update = false,
        public bool $strict_headers = false,
    ) {}
}
