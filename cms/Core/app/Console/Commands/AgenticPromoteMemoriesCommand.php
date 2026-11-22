<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Services\Agentic\AgentMemoryPolicyPromotionService;

/**
 * Promotes high-confidence agent memories to active policies.
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0
 */
class AgenticPromoteMemoriesCommand extends Command
{
    protected $signature = 'agentic:promote-memories
        {--min-confidence=0.85 : Minimum confidence score for promotion}
        {--min-usage=3 : Minimum usage count for promotion}';

    protected $description = 'Promote high-confidence agent memories to active policies';

    public function handle(AgentMemoryPolicyPromotionService $service): int
    {
        $minConfidence = (float) $this->option('min-confidence');
        $minUsage = (int) $this->option('min-usage');

        $this->info("Promoting memories with confidence >= {$minConfidence} and usage >= {$minUsage}...");

        $result = $service->promote($minConfidence, $minUsage);

        $this->info("Promoted: {$result['promoted']}");
        $this->info("Skipped: {$result['skipped']}");

        if ($result['promoted'] > 0) {
            $this->line('Promoted memory IDs: ' . implode(', ', $result['memories']));
        }

        return self::SUCCESS;
    }
}
