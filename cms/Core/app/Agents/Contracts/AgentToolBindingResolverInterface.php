<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Agents\Support\AgentToolBindingData;

/**
 * Contract for resolving agent tool bindings by name and runtime context.
 *
 * Given a tool name and optional scoping parameters (environment, tenant, module,
 * policy), the resolver selects the most appropriate binding configuration from
 * the available candidates.
 *
 * Implementors: {@see \Cms\Core\Agents\Tools\DefaultAgentToolBindingResolver}.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface AgentToolBindingResolverInterface
{
    /**
     * Resolve the best-matching tool binding for the given tool name and context.
     *
     * @param  string      $toolName     The canonical tool name to resolve.
     * @param  string|null $environment  Optional runtime environment (e.g. production, staging).
     * @param  string|null $tenantId     Optional tenant identifier for multi-tenant scoping.
     * @param  string|null $moduleScope  Optional CMS module scope (e.g. core, education).
     * @param  string|null $policyScope  Optional policy scope for additional filtering.
     * @return AgentToolBindingData|null The resolved binding data, or null if no match found.
     */
    public function resolve(
        string $toolName,
        ?string $environment = null,
        ?string $tenantId = null,
        ?string $moduleScope = null,
        ?string $policyScope = null
    ): ?AgentToolBindingData;
}
