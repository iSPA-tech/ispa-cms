<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Exceptions;

use RuntimeException;

/**
 * Thrown when the workflow context fails to serialize or deserialize to/from JSON.
 *
 * Indicates issues such as circular references, non-serializable objects, or JSON encoding errors
 * encountered during context snapshot operations.
 *
 * @package Cms\Core\Workflows\Runtime\Exceptions
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowSerializationException extends RuntimeException
{
}
