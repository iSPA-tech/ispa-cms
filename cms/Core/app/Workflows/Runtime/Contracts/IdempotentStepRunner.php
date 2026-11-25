<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Contracts;

/**
 * Marker for step runners that are safe to run again after a timeout: running twice has the same effect as running
 * once (e.g. a read). A step whose run finished after its `timeoutSeconds` is failed; it is retried only when its
 * runner implements this interface, because a slow write (POST, email) may already have taken effect.
 *
 * @package Cms\Core\Workflows\Runtime\Contracts
 * @since   2026-11-25
 */
interface IdempotentStepRunner extends StepRunner
{
}
