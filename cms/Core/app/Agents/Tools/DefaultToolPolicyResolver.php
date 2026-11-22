<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\ToolAuthorization;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Contracts\ToolPolicyResolverInterface;

/**
 * Default tool policy resolver enforcing profile allowlists, capability classes, and active policies.
 *
 * Checks authorization in order: kill switch, profile tool grants and allowlist,
 * the delegating user's ability (only for tools that declare one), capability-class
 * side-effect restrictions, active-policy blocklists, and finally determines whether
 * human approval is required based on the tool definition and capability configuration.
 *
 * @see ToolPolicyResolverInterface — implements this contract
 *
 * @package Cms\Core\Agents\Tools
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DefaultToolPolicyResolver implements ToolPolicyResolverInterface
{
    /**
     * Create a new DefaultToolPolicyResolver instance.
     *
     * @param Repository $config The configuration repository for capability class settings.
     */
    public function __construct(protected Repository $config)
    {
    }

    /**
     * Authorize a tool invocation against the agent's profile, capabilities, and policies.
     *
     * Denies if: the profile has no tool grants, the tool is not in the profile allowlist,
     * the tool declares an ability and the run has no loadable delegating user or that user
     * fails the Gate check, the tool's side-effect class is not permitted by the capability
     * class, or the tool is explicitly blocked by active policies.
     *
     * @param  AgentState              $state      The current agent state with profile and policy data.
     * @param  AgentToolDefinitionData $definition The tool definition to authorize.
     * @return ToolAuthorization The authorization result (allow/deny with optional approval requirement).
     */
    public function authorize(AgentState $state, AgentToolDefinitionData $definition): ToolAuthorization
    {
        // Kill switch: global emergency stop for all agent execution
        if ((bool) $this->config->get('core.agentic.kill_switch', false)) {
            return ToolAuthorization::deny('kill_switch_active');
        }

        if ($state->availableToolDefinitions === []) {
            return ToolAuthorization::deny('profile_has_no_tool_grants');
        }

        if (!in_array($definition->toolName, $state->availableToolDefinitions, true)) {
            return ToolAuthorization::deny('tool_not_in_profile_allowlist');
        }

        // Tools declaring an ability run only for a delegating user who holds it.
        if (is_string($definition->ability) && $definition->ability !== '') {
            $user = $this->resolveDelegatingUser($state->delegatedByUserId);

            if ($user === null) {
                return ToolAuthorization::deny('delegating_user_missing');
            }

            if (Gate::forUser($user)->denies($definition->ability)) {
                return ToolAuthorization::deny('delegating_user_lacks_ability');
            }
        }

        $capabilityConfig = $state->profileCapabilityClass
            ? (array) $this->config->get("core.agentic.capability_classes.{$state->profileCapabilityClass}", [])
            : [];

        $allowedSideEffects = (array) ($capabilityConfig['allowed_side_effect_classes'] ?? []);
        if ($allowedSideEffects !== [] && !in_array($definition->sideEffectClass, $allowedSideEffects, true)) {
            return ToolAuthorization::deny('side_effect_not_allowed_for_capability_class');
        }

        $blockedTools = (array) ($state->activePolicies['blocked_tools'] ?? []);
        if (in_array($definition->toolName, $blockedTools, true)) {
            return ToolAuthorization::deny('tool_blocked_by_policy');
        }

        $requiresApproval = $definition->approvalRequired
            || (
                (bool) ($capabilityConfig['approval_required'] ?? false)
                && $definition->sideEffectClass !== 'read_only'
            );

        return ToolAuthorization::allow($requiresApproval);
    }

    /**
     * Load the user who delegated the run through the configured auth user model.
     *
     * @param  int|null $userId The delegating user ID carried on the agent state.
     * @return Authenticatable|null The user, or null when no ID is set or no row exists.
     */
    protected function resolveDelegatingUser(?int $userId): ?Authenticatable
    {
        if ($userId === null) {
            return null;
        }

        $model = (string) $this->config->get('auth.providers.users.model', \App\Models\User::class);
        $user = $model::query()->find($userId);

        return $user instanceof Authenticatable ? $user : null;
    }
}
