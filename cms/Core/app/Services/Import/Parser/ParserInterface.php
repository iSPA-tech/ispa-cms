<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Parser;

use Illuminate\Http\UploadedFile;
use Cms\Core\Services\Import\ImportOptions;

/**
 * Contract for import file parsers.
 *
 * Implementations must yield rows as associative arrays from the
 * given uploaded file, using the first row as headers.
 *
 * @see ParserRegistry
 * @package Cms\Core\Services\Import\Parser
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface ParserInterface
{
    /**
     * @param  UploadedFile $file
     * @param  ImportOptions $options
     * @return iterable<array> Yields raw rows as associative arrays or indexed arrays
     */
    public function rows(UploadedFile $file, ImportOptions $options): iterable;
}
