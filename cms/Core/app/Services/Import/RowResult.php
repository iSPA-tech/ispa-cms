<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

/**
 * Value object representing the result of processing a single import row.
 *
 * Contains the row index, success status, action taken (create, update,
 * skip, fail), validation errors, message, and identifier values for
 * error reporting and CSV export.
 *
 * @see CoreImportEngine
 * @see ImportReport
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RowResult
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_UPDATE = 'update';
    public const string ACTION_SKIP = 'skip';
    public const string ACTION_FAIL = 'fail';

    /**
     * Initialize the row result with index, outcome, action, errors, and identifiers.
     *
     * @param int         $rowIndex    The 1-based row index in the source file.
     * @param bool        $success     Whether the row was processed successfully.
     * @param string      $action      The action taken: 'create', 'update', 'skip', or 'fail'.
     * @param array       $errors      Validation errors for this row.
     * @param string|null $message     Optional message describing the outcome.
     * @param array       $identifiers Key-value pairs identifying the row (e.g., ['id' => 123]).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public int     $rowIndex,
        public bool    $success,
        public string  $action, // create, update, skip, fail
        public array   $errors = [],
        public ?string $message = null,
        public array   $identifiers = [], // e.g. ['id' => 123, 'code' => 'ABC']
    )
    {
    }
}
