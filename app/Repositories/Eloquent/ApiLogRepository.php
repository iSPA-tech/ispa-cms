<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Tracking\ApiLog;
use App\Repositories\Interfaces\ApiLogInterface;

class ApiLogRepository extends RepositoriesAbstract implements ApiLogInterface
{
    public function __construct()
    {
        $this->setModel(ApiLog::class);
    }
}
