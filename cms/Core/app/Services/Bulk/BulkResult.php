<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk;

/**
 * Value object representing the outcome of a bulk action execution.
 *
 * Contains success status, count of affected records, their IDs,
 * optional metadata, and any error messages. Provides toArray()
 * for API response serialization.
 *
 * @package Cms\Core\Services\Bulk
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class BulkResult
{
    /**
     * @param array<int, int|string> $ids
     * @param array<string, mixed> $meta
     * @param array<int, string> $errors
     * @param  bool $success
     * @param  int $affected
     */
    public function __construct(
        public bool $success = true,
        public int $affected = 0,
        public array $ids = [],
        public array $meta = [],
        public array $errors = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'affected' => $this->affected,
            'ids' => $this->ids,
            'meta' => $this->meta,
            'errors' => $this->errors,
        ];
    }
}
