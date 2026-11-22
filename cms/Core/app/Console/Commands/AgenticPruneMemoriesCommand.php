<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Models\Agents\AgentMemory;

/**
 * Prunes expired and deprecated agent memories to prevent unbounded growth.
 *
 * Deletes memories that have passed their expires_at TTL or have been
 * marked as deprecated. Optionally prunes low-confidence memories below
 * a configurable threshold.
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0
 */
class AgenticPruneMemoriesCommand extends Command
{
    protected $signature = 'agentic:prune-memories
        {--min-confidence=0 : Delete memories with confidence below this threshold}
        {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Prune expired and deprecated agent memories';

    public function handle(): int
    {
        $minConfidence = (float) $this->option('min-confidence');
        $dryRun = (bool) $this->option('dry-run');

        // Count expired memories
        $expiredCount = AgentMemory::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        // Count deprecated memories
        $deprecatedCount = AgentMemory::query()
            ->where('quality_state', 'deprecated')
            ->count();

        // Count low-confidence memories (if threshold set)
        $lowConfidenceCount = 0;
        if ($minConfidence > 0) {
            $lowConfidenceCount = AgentMemory::query()
                ->whereNotNull('confidence_score')
                ->where('confidence_score', '<', $minConfidence)
                ->where('quality_state', '!=', 'verified')
                ->count();
        }

        $totalCount = $expiredCount + $deprecatedCount + $lowConfidenceCount;

        if ($totalCount === 0) {
            $this->info('No memories to prune.');
            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} memories to prune:");
        $this->line("  - Expired: {$expiredCount}");
        $this->line("  - Deprecated: {$deprecatedCount}");
        if ($minConfidence > 0) {
            $this->line("  - Low confidence (<{$minConfidence}): {$lowConfidenceCount}");
        }

        if ($dryRun) {
            $this->warn('Dry run — no memories deleted.');
            return self::SUCCESS;
        }

        $deleted = 0;

        // Delete expired
        $deleted += AgentMemory::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        // Delete deprecated
        $deleted += AgentMemory::query()
            ->where('quality_state', 'deprecated')
            ->delete();

        // Delete low-confidence (if threshold set)
        if ($minConfidence > 0) {
            $deleted += AgentMemory::query()
                ->whereNotNull('confidence_score')
                ->where('confidence_score', '<', $minConfidence)
                ->where('quality_state', '!=', 'verified')
                ->delete();
        }

        $this->info("Pruned {$deleted} memories.");

        return self::SUCCESS;
    }
}
