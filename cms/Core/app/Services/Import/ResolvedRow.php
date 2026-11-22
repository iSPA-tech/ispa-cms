<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

/**
 * Represents a row after transformation and mapping in the import pipeline.
 *
 * Contains the resolved data array, the 1-based row index from the source
 * file, and the list of keys present in the original source or added by
 * transformers. Used for validation, unique-key matching, and upsert.
 *
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ResolvedRow
{
    /**
     * Initialize the resolved row with mapped data, source index, and present keys.
     *
     * @param array $data        The resolved data array after transformation.
     * @param int   $rowIndex    The 1-based row index from the source file.
     * @param array $presentKeys Keys present in the source or added by transformers.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public array $data,
        public int $rowIndex, // 1-based index in file (including header)
        public array $presentKeys = [], // Keys that were present in source or added by transformers
    ) {}
    
    /**
     * Get a value from the resolved data by key.
     *
     * @param string $key     The data key.
     * @param mixed  $default Default value if key is not found.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
