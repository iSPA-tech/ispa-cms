<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Cms\Core\Services\Agentic\AgenticPreflightChecker;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgentToolProvisioner;
use Cms\Core\Responses\AppResponse;

/**
 * Provisioning endpoints for the agentic subsystem.
 *
 * Handles CRUD operations for agent profiles, tool synchronization,
 * tool-to-profile grant management, and tool binding configuration.
 * These endpoints allow administrators to set up and maintain the
 * agentic infrastructure.
 *
 * @see AgentToolProvisioner
 * @see AgenticPreflightChecker
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticProvisioningController extends Controller
{
    /**
     * Create a new agent profile.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgentToolProvisioner   $provisioner    Tool provisioning service
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Created profile data
     */
    public function storeProfile(
        Request $request,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), $this->profileRules());
        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $profile = $provisioner->createProfile($this->profilePayload($request));
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.profile-created', ['name' => $profile->name]))
            ->data([
                'profile' => $this->serializeProfile($profile),
            ]);
    }

    /**
     * Update an existing agent profile.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  string                 $profile        Profile ID or slug
     * @param  AgentToolProvisioner   $provisioner    Tool provisioning service
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Updated profile data
     */
    public function updateProfile(
        Request $request,
        string $profile,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedProfile = $provisioner->resolveProfile($profile);
        if (! $resolvedProfile) {
            return ires()->notFound(__('mgs.agentic.profile-not-found'));
        }

        $validator = Validator::make($request->all(), $this->profileRules($resolvedProfile->id, true));
        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $updatedProfile = $provisioner->updateProfile(
                (string) $resolvedProfile->getKey(),
                $this->profilePayload($request, true)
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.profile-updated', ['name' => $updatedProfile->name]))
            ->data([
                'profile' => $this->serializeProfile($updatedProfile),
            ]);
    }

    /**
     * Seed starter agent profiles, tool grants, and schedules.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgentToolProvisioner   $provisioner    Tool provisioning service
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Seeded profiles, grants, and schedule counts
     */
    public function seedTemplateProfiles(
        Request $request,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        try {
            $result = $provisioner->seedTemplateProfiles($request->user());
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(sprintf(
                'Starter setup ready. Profiles: %d (%d created, %d reused), grants: %d, starter schedules: %d created, %d reused.',
                $result['profiles']->count(),
                $result['created'],
                $result['existing'],
                array_sum(array_map('count', $result['grants'])),
                $result['schedules_created'],
                $result['schedules_existing'],
            ))
            ->data([
                'profiles' => $result['profiles']->map(fn ($profile) => $this->serializeProfile($profile))->values()->all(),
                'created' => $result['created'],
                'existing' => $result['existing'],
                'synced_tools' => $result['synced_tools'],
                'grants' => $result['grants'],
                'missing_tools' => $result['missing_tools'],
                'schedules_created' => $result['schedules_created'],
                'schedules_existing' => $result['schedules_existing'],
                'starter_selection' => $result['starter_selection'],
            ]);
    }

    /**
     * Synchronize tool definitions from configured service providers.
     *
     * @param  Request                  $request        The incoming HTTP request
     * @param  AgentToolProvisioner     $provisioner    Tool provisioning service
     * @param  AgenticPreflightChecker  $checker        Preflight readiness checker
     * @param  AgenticAuthorization     $authorization  Authorization service
     * @return AppResponse  Sync results with persisted tool counts
     */
    public function sync(
        Request $request,
        AgentToolProvisioner $provisioner,
        AgenticPreflightChecker $checker,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'environment' => ['nullable', 'string', 'max:120'],
            'services' => ['nullable', 'array'],
            'services.*' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        $services = $request->input('services', []);
        if (! is_array($services)) {
            return ires()->badRequest(__('mgs.agentic.services-must-be-array'));
        }

        $environment = $request->filled('environment')
            ? $request->string('environment')->toString()
            : null;

        try {
            $checker->assertSyncReady($services, $environment);

            $results = $provisioner->syncServices($services, $environment);
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        $total = array_sum(array_map(
            fn (array $result) => count($result['tool_names'] ?? []),
            $results
        ));

        return ires()
            ->message(__('mgs.agentic.sync-complete', ['total' => $total]))
            ->data([
                'results' => $results,
                'total' => $total,
                'environment' => $environment,
            ]);
    }

    /**
     * Grant tool definitions to a specific agent profile.
     *
     * @param  Request                  $request        The incoming HTTP request
     * @param  string                   $profile        Profile ID or slug
     * @param  AgentToolProvisioner     $provisioner    Tool provisioning service
     * @param  AgenticPreflightChecker  $checker        Preflight readiness checker
     * @param  AgenticAuthorization     $authorization  Authorization service
     * @return AppResponse  Granted tool names and profile data
     */
    public function grantProfileToolDefinitions(
        Request $request,
        string $profile,
        AgentToolProvisioner $provisioner,
        AgenticPreflightChecker $checker,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'tool_names' => ['required', 'array', 'min:1'],
            'tool_names.*' => ['required', 'string', 'max:190'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $checker->assertGrantReady();
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        $resolvedProfile = $provisioner->resolveProfile($profile);
        if (! $resolvedProfile) {
            return ires()->notFound(__('mgs.agentic.profile-not-found'));
        }

        try {
            $result = $provisioner->grantToolsToProfile(
                (string) $resolvedProfile->getKey(),
                array_values((array) $request->input('tool_names', [])),
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        if ($result['missing'] !== []) {
            return ires()->badRequest(__('mgs.agentic.unknown-tool-definitions', ['names' => implode(', ', $result['missing'])]));
        }

        return ires()
            ->message(sprintf(
                'Granted %d tool(s) to profile [%s].',
                count($result['granted']),
                $result['profile']->slug
            ))
            ->data([
                'profile' => $this->serializeProfile($result['profile']),
                'granted' => $result['granted'],
                'missing' => [],
            ]);
    }

    /**
     * Update a profile-to-tool grant (enabled flag, safety override).
     *
     * @param  Request                $request         The incoming HTTP request
     * @param  string                 $profile         Profile ID or slug
     * @param  string                 $toolDefinition  Tool definition ID
     * @param  AgentToolProvisioner   $provisioner     Tool provisioning service
     * @param  AgenticAuthorization   $authorization   Authorization service
     * @return AppResponse  Updated grant data
     */
    public function updateProfileToolDefinitionGrant(
        Request $request,
        string $profile,
        string $toolDefinition,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'enabled' => ['nullable', 'boolean'],
            'safety_override' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        $attributes = [];
        if ($request->has('enabled')) {
            $attributes['enabled'] = (bool) $request->boolean('enabled');
        }
        if ($request->exists('safety_override')) {
            $attributes['safety_override'] = $request->filled('safety_override')
                ? $request->string('safety_override')->toString()
                : null;
        }

        if ($attributes === []) {
            return ires()->badRequest(__('mgs.agentic.no-grant-attrs'));
        }

        try {
            $result = $provisioner->updateProfileToolGrant($profile, $toolDefinition, $attributes);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'not found')) {
                return ires()->notFound($message);
            }

            return ires()->badRequest($message);
        }

        return ires()
            ->message(sprintf(
                'Updated grant for tool [%s] on profile [%s].',
                $result['definition']->tool_name,
                $result['profile']->slug
            ))
            ->data([
                'profile' => $this->serializeProfile($result['profile']),
                'grant' => $this->serializeGrant($result['definition'], $result['grant']),
            ]);
    }

    /**
     * Revoke a tool definition grant from a specific agent profile.
     *
     * @param  Request                $request         The incoming HTTP request
     * @param  string                 $profile         Profile ID or slug
     * @param  string                 $toolDefinition  Tool definition ID
     * @param  AgentToolProvisioner   $provisioner     Tool provisioning service
     * @param  AgenticAuthorization   $authorization   Authorization service
     * @return AppResponse  Revocation confirmation with profile data
     */
    public function revokeProfileToolDefinitionGrant(
        Request $request,
        string $profile,
        string $toolDefinition,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        try {
            $result = $provisioner->revokeProfileToolGrant($profile, $toolDefinition);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'not found')) {
                return ires()->notFound($message);
            }

            return ires()->badRequest($message);
        }

        return ires()
            ->message(sprintf(
                'Revoked tool [%s] from profile [%s].',
                $result['definition']->tool_name,
                $result['profile']->slug
            ))
            ->data([
                'profile' => $this->serializeProfile($result['profile']),
                'revoked' => [
                    'id' => $result['definition']->id,
                    'tool_name' => $result['definition']->tool_name,
                ],
            ]);
    }

    /**
     * Update an existing tool binding (enabled flag, priority).
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  string                 $binding        Binding ID
     * @param  AgentToolProvisioner   $provisioner    Tool provisioning service
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Updated binding data
     */
    public function updateToolBinding(
        Request $request,
        string $binding,
        AgentToolProvisioner $provisioner,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        $attributes = [];
        if ($request->has('enabled')) {
            $attributes['enabled'] = (bool) $request->boolean('enabled');
        }
        if ($request->has('priority')) {
            $attributes['priority'] = (int) $request->input('priority');
        }

        if ($attributes === []) {
            return ires()->badRequest(__('mgs.agentic.no-binding-attrs'));
        }

        try {
            $result = $provisioner->updateToolBinding($binding, $attributes);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'not found')) {
                return ires()->notFound($message);
            }

            return ires()->badRequest($message);
        }

        return ires()
            ->message(sprintf(
                'Updated binding [%d] for tool [%s].',
                $result['binding']->id,
                $result['binding']->definition?->tool_name ?? 'unknown'
            ))
            ->data([
                'binding' => $this->serializeBinding($result['binding']),
            ]);
    }

    /**
     * @return array{id: int, name: string, slug: string, description: string|null, capability_class: string|null, status: string|null, system_prompt_template: string|null, model_config: array<string, mixed>|null, budget_config: array<string, mixed>|null}
     */
    protected function serializeProfile($profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'slug' => $profile->slug,
            'description' => $profile->description,
            'capability_class' => $profile->capability_class,
            'status' => $profile->status,
            'system_prompt_template' => $profile->system_prompt_template,
            'model_config' => $profile->model_config,
            'budget_config' => $profile->budget_config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeGrant($definition, $grant): array
    {
        return [
            'id' => $definition->id,
            'tool_name' => $definition->tool_name,
            'tool_family' => $definition->tool_family,
            'contract_steward' => $definition->contract_steward,
            'capability_class' => $definition->capability_class,
            'status' => $definition->status,
            'grant' => [
                'enabled' => (bool) $grant->enabled,
                'safety_override' => $grant->safety_override,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeBinding($binding): array
    {
        return [
            'id' => $binding->id,
            'tool_definition_id' => $binding->tool_definition_id,
            'tool_name' => $binding->definition?->tool_name,
            'module_scope' => $binding->module_scope,
            'environment' => $binding->environment,
            'policy_scope' => $binding->policy_scope,
            'binding_type' => $binding->binding_type,
            'binding_target' => $binding->binding_target,
            'enabled' => (bool) $binding->enabled,
            'priority' => $binding->priority,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function profileRules(?int $ignoreProfileId = null, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];
        $capabilityKeys = array_keys((array) config('core.agentic.capability_classes', []));
        $slugRule = Rule::unique('agent_profiles', 'slug');

        if ($ignoreProfileId !== null) {
            $slugRule = $slugRule->ignore($ignoreProfileId);
        }

        return [
            'name' => [...$required, 'string', 'max:190'],
            'slug' => [...$required, 'string', 'max:190', 'alpha_dash', $slugRule],
            'description' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string'],
            'capability_class' => [...$required, 'string', Rule::in($capabilityKeys)],
            'status' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:32'],
            'system_prompt_template' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string'],
            'model_config' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'array'],
            'budget_config' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function profilePayload(Request $request, bool $partial = false): array
    {
        $payload = [];

        if (! $partial || $request->has('name')) {
            $payload['name'] = $request->string('name')->toString();
        }

        if (! $partial || $request->has('slug')) {
            $payload['slug'] = $request->string('slug')->toString();
        }

        if (! $partial || $request->exists('description')) {
            $payload['description'] = $request->filled('description')
                ? $request->string('description')->toString()
                : null;
        }

        if (! $partial || $request->has('capability_class')) {
            $payload['capability_class'] = $request->string('capability_class')->toString();
        }

        if (! $partial || $request->exists('status')) {
            $payload['status'] = $request->filled('status')
                ? $request->string('status')->toString()
                : 'active';
        }

        if (! $partial || $request->exists('system_prompt_template')) {
            $payload['system_prompt_template'] = $request->filled('system_prompt_template')
                ? $request->string('system_prompt_template')->toString()
                : null;
        }

        if (! $partial || $request->exists('model_config')) {
            $payload['model_config'] = $request->input('model_config');
        }

        if (! $partial || $request->exists('budget_config')) {
            $payload['budget_config'] = $request->input('budget_config');
        }

        return $payload;
    }
}
