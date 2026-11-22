<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Expression\Exceptions;

use RuntimeException;

/**
 * Thrown when a workflow expression fails validation before execution.
 *
 * Indicates disallowed syntax, forbidden function calls, or structural issues detected
 * during expression pre-validation. Optionally carries the character position of the error.
 *
 * @package Cms\Core\Workflows\Expression\Exceptions
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ExpressionValidationException extends RuntimeException
{
    /**
     * @param string          $message  The validation error message.
     * @param int|null        $position The character position where the error was detected.
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message, protected ?int $position = null, \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the character position where the validation error was detected.
     *
     * @return int|null The character position, or null if not available.
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }
}
