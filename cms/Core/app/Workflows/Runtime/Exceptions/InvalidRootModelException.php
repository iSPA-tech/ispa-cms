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
 * Thrown when the workflow root model class is invalid, non-existent, or not an Eloquent model.
 *
 * @package Cms\Core\Workflows\Runtime\Exceptions
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class InvalidRootModelException extends RuntimeException
{
}
