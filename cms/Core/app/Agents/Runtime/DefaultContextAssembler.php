<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentMemory;
use Cms\Core\Agents\Contracts\ContextAssemblerInterface;

/**
 * Default implementation of the context assembler for agent runs.
 *
 * Builds a structured context snapshot containing run metadata, target reference,
 * working memory, and checkpoint state. This snapshot is consumed by the reasoning
 * engine during the planning phase.
 *
 * @see ContextAssemblerInterface — implements this contract
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DefaultContextAssembler implements ContextAssemblerInterface
{
    /**
     * Assemble a context snapshot for the given agent run.
     *
     * @param  AgentRun $run The current agent run whose context is being assembled.
     * @return array<string, mixed> The assembled context snapshot with run, target, working_memory, and checkpoint sections.
     */
    public function assemble(AgentRun $run): array
    {
        return [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'goal' => $run->input_goal,
                'environment' => $run->environment,
                'tenant_id' => $run->tenant_id,
                'module_scope' => $run->module_scope,
            ],
            'target' => [
                'type' => $run->target_type,
                'id' => $run->target_id,
            ],
            'working_memory' => $run->working_memory ?? [],
            'checkpoint' => [
                'last_checkpoint_at' => optional($run->last_checkpoint_at)?->toIso8601String(),
                'terminal_reason' => $run->terminal_reason,
                'post_approval_outcome' => $run->post_approval_outcome,
            ],
            'agent_memories' => $this->loadRelevantMemories($run),
        ];
    }

    /**
     * Load relevant agent memories for the run context (C-5).
     *
     * Queries active, non-expired memories scoped to the run's target entity
     * and module scope. Returns up to 20 most recent entries, ordered by
     * last_used_at for recency.
     *
     * @param  AgentRun $run The current agent run.
     * @return array<int, array<string, mixed>> Memory entries for context assembly.
     */
    protected function loadRelevantMemories(AgentRun $run): array
    {
        return AgentMemory::query()
            ->where('quality_state', '!=', 'deprecated')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($run) {
                $query->where(function ($q) use ($run) {
                    $q->where('entity_type', $run->target_type)
                        ->where('entity_id', $run->target_id);
                });
                if ($run->module_scope) {
                    $query->orWhere('context_key', 'like', "approval_feedback.{$run->module_scope}.%");
                }
            })
            ->orderByDesc('last_used_at')
            ->limit(20)
            ->get(['memory_class', 'memory_type', 'context_key', 'content', 'confidence_score'])
            ->toArray();
    }
}
