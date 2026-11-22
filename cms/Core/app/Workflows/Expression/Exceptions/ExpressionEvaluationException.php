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
 * Thrown when a workflow expression fails during evaluation at runtime.
 *
 * This may be caused by syntax errors, undefined variables, or disallowed function calls
 * within the expression engine.
 *
 * @package Cms\Core\Workflows\Expression\Exceptions
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ExpressionEvaluationException extends RuntimeException
{
}
