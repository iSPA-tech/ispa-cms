<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Cms\Core\Services\Agentic\AgentToolProvisioner;

class AgenticProfileTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(AgentToolProvisioner::class)->seedTemplateProfiles();

        Log::info(sprintf(
            'AgenticProfileTemplateSeeder finished. Created %d sample profile(s), reused %d existing.',
            $result['created'],
            $result['existing'],
        ));
    }
}
