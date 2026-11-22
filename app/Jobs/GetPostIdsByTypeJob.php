<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

use Cms\Content\Jobs\GetPostIdsByTypeJob as ContentModuleGetPostIdsByTypeJob;

/**
 * Deprecated alias kept so queued payloads serialized under the old class name still run.
 *
 * @deprecated Use \Cms\Content\Jobs\GetPostIdsByTypeJob. Moved on 2026-11-21; remove one release later.
 */
class GetPostIdsByTypeJob extends ContentModuleGetPostIdsByTypeJob
{
}
