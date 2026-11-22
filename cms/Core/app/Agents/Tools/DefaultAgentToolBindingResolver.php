<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Tools;

use Cms\Core\Agents\Support\AgentToolBindingData;
use Cms\Core\Agents\Contracts\AgentToolBindingResolverInterface;

/**
 * Default binding resolver that selects the best-matching tool binding by score.
 *
 * Filters enabled bindings from the registry, scores each candidate based on
 * how closely its scoping dimensions (environment, module, tenant, policy)
 * match the requested context, and returns the highest-scoring binding.
 * Mismatched non-null dimensions disqualify a candidate entirely.
 *
 * @see AgentToolBindingResolverInterface — implements this contract
 *
 * @package Cms\Core\Agents\Tools
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DefaultAgentToolBindingResolver implements AgentToolBindingResolverInterface
{
    /**
     * Create a new DefaultAgentToolBindingResolver instance.
     *
     * @param AgentToolRegistry $registry The tool registry providing binding candidates.
     */
    public function __construct(protected AgentToolRegistry $registry)
    {
    }

    /**
     * Resolve the best-matching tool binding for the given tool name and context.
     *
     * @param  string      $toolName     The canonical tool name to resolve.
     * @param  string|null $environment  Optional runtime environment filter.
     * @param  string|null $tenantId     Optional tenant identifier filter.
     * @param  string|null $moduleScope  Optional module scope filter.
     * @param  string|null $policyScope  Optional policy scope filter.
     * @return AgentToolBindingData|null The best-matching binding, or null if none found.
     */
    public function resolve(
        string $toolName,
        ?string $environment = null,
        ?string $tenantId = null,
        ?string $moduleScope = null,
        ?string $policyScope = null
    ): ?AgentToolBindingData {
        $candidates = array_filter(
            $this->registry->bindingsFor($toolName),
            fn (AgentToolBindingData $binding) => $binding->enabled
        );

        if ($candidates === []) {
            return null;
        }

        $scored = [];
        foreach ($candidates as $binding) {
            $score = $this->scoreBinding($binding, $environment, $tenantId, $moduleScope, $policyScope);
            if ($score < 0) {
                continue;
            }

            $scored[] = ['binding' => $binding, 'score' => $score];
        }

        if ($scored === []) {
            return null;
        }

        usort($scored, static function (array $left, array $right): int {
            return [$right['score'], $right['binding']->priority] <=> [$left['score'], $left['binding']->priority];
        });

        return $scored[0]['binding'];
    }

    /**
     * Score a binding candidate against the requested context dimensions.
     *
     * Each matching dimension adds its weight to the score. If a binding has a
     * non-null dimension that does not match the request, the score is -1
     * (disqualified).
     *
     * Weights: environment=16, moduleScope=8, tenantId=4, policyScope=2.
     *
     * @param  AgentToolBindingData $binding     The binding candidate.
     * @param  string|null          $environment The requested environment.
     * @param  string|null          $tenantId    The requested tenant ID.
     * @param  string|null          $moduleScope The requested module scope.
     * @param  string|null          $policyScope The requested policy scope.
     * @return int The binding score (>= 0 for valid, -1 for disqualified).
     */
    protected function scoreBinding(
        AgentToolBindingData $binding,
        ?string $environment,
        ?string $tenantId,
        ?string $moduleScope,
        ?string $policyScope
    ): int {
        $score = 0;

        foreach ([
            [$binding->environment, $environment, 16],
            [$binding->moduleScope, $moduleScope, 8],
            [$binding->tenantId, $tenantId, 4],
            [$binding->policyScope, $policyScope, 2],
        ] as [$bindingValue, $requestedValue, $weight]) {
            if ($bindingValue === null || $bindingValue === '') {
                continue;
            }

            if ($requestedValue === null || $bindingValue !== $requestedValue) {
                return -1;
            }

            $score += $weight;
        }

        return $score;
    }
}
