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
 * Thrown when a template string containing {{ expression }} blocks fails to render.
 *
 * Provides context about which expression within which template caused the failure,
 * aiding in debugging workflow template rendering issues.
 *
 * @package Cms\Core\Workflows\Expression\Exceptions
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TemplateRenderException extends RuntimeException
{
    /**
     * @param string          $template   The full template string that failed to render.
     * @param string          $expression The specific expression that caused the failure.
     * @param string          $message    The underlying error message.
     * @param \Throwable|null $previous   The previous exception, if any.
     */
    public function __construct(string $template, string $expression, string $message, \Throwable $previous = null)
    {
        $snippet = mb_strimwidth($template, 0, 80, '...');
        parent::__construct("Failed to render expression '{$expression}' in template '{$snippet}': {$message}", 0, $previous);
    }
}
