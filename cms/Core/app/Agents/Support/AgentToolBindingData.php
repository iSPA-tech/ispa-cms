<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

/**
 * Immutable DTO representing a resolved tool binding configuration.
 *
 * Carries the scoping context (module, environment, tenant, policy), the binding
 * type and target (e.g. service_method class@method), rollout scope, and priority.
 * Produced by the binding resolver and consumed by the tool registry to instantiate
 * the callable for tool execution.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class AgentToolBindingData
{
    /**
     * Create a new AgentToolBindingData instance.
     *
     * @param string               $toolName      The canonical tool name.
     * @param string|null          $moduleScope   The CMS module scope.
     * @param string|null          $environment   The runtime environment.
     * @param string|null          $tenantId      The tenant identifier.
     * @param string|null          $policyScope   The policy scope.
     * @param array<string, mixed> $rolloutScope  Rollout/feature-flag scoping data.
     * @param string               $bindingType   The binding type (e.g. "service_method").
     * @param string               $bindingTarget The binding target (e.g. "Class@method").
     * @param array<string, mixed> $bindingConfig Additional binding configuration.
     * @param bool                 $enabled       Whether the binding is active.
     * @param int                  $priority      Priority for binding resolution ordering.
     */
    public function __construct(
        public string $toolName,
        public ?string $moduleScope = null,
        public ?string $environment = null,
        public ?string $tenantId = null,
        public ?string $policyScope = null,
        public array $rolloutScope = [],
        public string $bindingType = 'service_method',
        public string $bindingTarget = '',
        public array $bindingConfig = [],
        public bool $enabled = true,
        public int $priority = 0,
    ) {
    }

    /**
     * Convert the binding data to a plain associative array.
     *
     * @return array<string, mixed> The binding data as a serializable array.
     */
    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'module_scope' => $this->moduleScope,
            'environment' => $this->environment,
            'tenant_id' => $this->tenantId,
            'policy_scope' => $this->policyScope,
            'rollout_scope' => $this->rolloutScope,
            'binding_type' => $this->bindingType,
            'binding_target' => $this->bindingTarget,
            'binding_config' => $this->bindingConfig,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
        ];
    }
}
