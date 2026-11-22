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
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgenticPreflightChecker;

/**
 * Read-only catalog endpoints for the agentic subsystem.
 *
 * Exposes health checks, tool definitions, tool bindings, agent profiles,
 * and profile-to-tool grants. These endpoints are used by the admin UI to
 * inspect the current state of the agentic infrastructure.
 *
 * @see AgentToolDefinition
 * @see AgentProfile
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticCatalogController extends Controller
{
    /**
     * Return a health-check summary of the agentic subsystem.
     *
     * @param  Request                  $request        The incoming HTTP request
     * @param  AgenticAuthorization     $authorization  Authorization service
     * @param  AgenticPreflightChecker  $checker        Preflight readiness checker
     * @param  ConfigRepository         $config         Application configuration
     * @return AppResponse  Health status with provider, runtime, and preflight details
     */
    public function health(
        Request $request,
        AgenticAuthorization $authorization,
        AgenticPreflightChecker $checker,
        ConfigRepository $config,
    ): AppResponse {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $syncInspection = $checker->inspectSyncReadiness();
        $grantInspection = $checker->inspectGrantReadiness();
        $queueDriver = (string) $config->get('queue.default', 'sync');
        $schedulerEnabled = (bool) $config->get('core.agentic.scheduler.enabled', false);
        $schedulerBatchLimit = max(1, (int) $config->get('core.agentic.scheduler.batch_limit', 20));
        $aiDriver = $config->get('core.agentic.ai_provider.driver');
        $embeddingDriver = $config->get('core.agentic.embedding_provider.driver');
        $aiDriver = $aiDriver === null || $aiDriver === '' || $aiDriver === 'null' ? null : (string) $aiDriver;
        $embeddingDriver = $embeddingDriver === null || $embeddingDriver === '' || $embeddingDriver === 'null' ? null : (string) $embeddingDriver;
        $openAiKeyPresent = (bool) $config->get('services.openai.key');

        $issues = [];

        if ($syncInspection['missing_tables'] !== []) {
            $issues[] = [
                'level' => 'error',
                'code' => 'missing_sync_tables',
                'message' => __('mgs.agentic.health-missing-sync-tables'),
                'details' => $syncInspection['missing_tables'],
            ];
        }

        if ($grantInspection['missing_tables'] !== []) {
            $issues[] = [
                'level' => 'error',
                'code' => 'missing_grant_tables',
                'message' => __('mgs.agentic.health-missing-grant-tables'),
                'details' => $grantInspection['missing_tables'],
            ];
        }

        if ($syncInspection['invalid_services'] !== []) {
            $issues[] = [
                'level' => 'error',
                'code' => 'invalid_bootstrap_services',
                'message' => __('mgs.agentic.health-invalid-services'),
                'details' => $syncInspection['invalid_services'],
            ];
        }

        if (in_array($aiDriver, ['openai'], true) && ! $openAiKeyPresent) {
            $issues[] = [
                'level' => 'error',
                'code' => 'missing_ai_key',
                'message' => __('mgs.agentic.health-missing-api-key'),
                'details' => [],
            ];
        }

        if ($queueDriver === 'sync') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'sync_queue_driver',
                'message' => __('mgs.agentic.health-sync-queue'),
                'details' => [],
            ];
        }

        if (! $schedulerEnabled) {
            $issues[] = [
                'level' => 'warn',
                'code' => 'scheduler_disabled',
                'message' => __('mgs.agentic.health-scheduler-disabled'),
                'details' => [],
            ];
        }

        $status = collect($issues)->contains(fn (array $issue) => $issue['level'] === 'error')
            ? 'error'
            : (count($issues) > 0 ? 'warn' : 'ok');

        return ires()->data([
            'status' => $status,
            'provider' => [
                'ai_driver' => $aiDriver,
                'embedding_driver' => $embeddingDriver,
                'api_key_configured' => $openAiKeyPresent,
            ],
            'runtime' => [
                'queue_driver' => $queueDriver,
                'scheduler_enabled' => $schedulerEnabled,
                'scheduler_batch_limit' => $schedulerBatchLimit,
            ],
            'preflight' => [
                'sync_ready' => $syncInspection['missing_tables'] === [] && $syncInspection['invalid_services'] === [],
                'grant_ready' => $grantInspection['missing_tables'] === [],
                'resolved_services' => $syncInspection['resolved_services'],
                'missing_sync_tables' => $syncInspection['missing_tables'],
                'missing_grant_tables' => $grantInspection['missing_tables'],
                'invalid_services' => $syncInspection['invalid_services'],
            ],
            'issues' => $issues,
        ]);
    }

    /**
     * List tool definitions with filtering and pagination.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Paginated list of tool definitions
     */
    public function definitions(Request $request, AgenticAuthorization $authorization): AppResponse
    {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        [$page, $perPage] = $this->pagination($request, 50);

        $query = AgentToolDefinition::query()
            ->withCount(['bindings', 'profiles'])
            ->when($request->filled('family'), fn ($q) => $q->where('tool_family', $request->string('family')->toString()))
            ->when($request->filled('capability_class'), fn ($q) => $q->where('capability_class', $request->string('capability_class')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('contract_steward'), fn ($q) => $q->where('contract_steward', $request->string('contract_steward')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search')->toString() . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('tool_name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('contract_steward', 'like', $term)
                        ->orWhere('tool_family', 'like', $term);
                });
            })
            ->orderBy('tool_name');

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentToolDefinition $definition) => [
                'id' => $definition->id,
                'tool_name' => $definition->tool_name,
                'tool_family' => $definition->tool_family,
                'contract_steward' => $definition->contract_steward,
                'description' => $definition->description,
                'safety_level' => $definition->safety_level,
                'side_effect_class' => $definition->side_effect_class,
                'citation_required' => $definition->citation_required,
                'approval_required' => $definition->approval_required,
                'capability_class' => $definition->capability_class,
                'status' => $definition->status,
                'timeout_seconds' => $definition->timeout_seconds,
                'binding_count' => $definition->bindings_count,
                'profile_count' => $definition->profiles_count,
            ])
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * List tool bindings with filtering and pagination.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Paginated list of tool bindings
     */
    public function bindings(Request $request, AgenticAuthorization $authorization): AppResponse
    {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        [$page, $perPage] = $this->pagination($request, 100);

        $query = AgentToolBinding::query()
            ->with('definition:id,tool_name,tool_family,capability_class')
            ->when($request->filled('tool_name'), function ($q) use ($request) {
                $toolName = $request->string('tool_name')->toString();
                $q->whereHas('definition', fn ($definitionQuery) => $definitionQuery->where('tool_name', $toolName));
            })
            ->when($request->filled('module_scope'), fn ($q) => $q->where('module_scope', $request->string('module_scope')->toString()))
            ->when($request->filled('environment'), fn ($q) => $q->where('environment', $request->string('environment')->toString()))
            ->when($request->filled('policy_scope'), fn ($q) => $q->where('policy_scope', $request->string('policy_scope')->toString()))
            ->when(($enabled = $this->nullableBoolean($request->query('enabled'))) !== null, fn ($q) => $q->where('enabled', $enabled))
            ->orderBy('tool_definition_id')
            ->orderByDesc('priority');

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentToolBinding $binding) => [
                'id' => $binding->id,
                'tool_definition_id' => $binding->tool_definition_id,
                'tool_name' => $binding->definition?->tool_name,
                'tool_family' => $binding->definition?->tool_family,
                'capability_class' => $binding->definition?->capability_class,
                'module_scope' => $binding->module_scope,
                'environment' => $binding->environment,
                'tenant_id' => $binding->tenant_id,
                'policy_scope' => $binding->policy_scope,
                'rollout_scope' => $binding->rollout_scope,
                'binding_type' => $binding->binding_type,
                'binding_target' => $binding->binding_target,
                'binding_config' => $binding->binding_config,
                'enabled' => $binding->enabled,
                'priority' => $binding->priority,
            ])
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * List agent profiles with filtering and pagination.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Paginated list of agent profiles
     */
    public function profiles(Request $request, AgenticAuthorization $authorization): AppResponse
    {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        [$page, $perPage] = $this->pagination($request, 50);

        $query = AgentProfile::query()
            ->withCount(['toolDefinitions', 'runs'])
            ->when($request->filled('capability_class'), fn ($q) => $q->where('capability_class', $request->string('capability_class')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search')->toString() . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('name');

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'slug' => $profile->slug,
                'description' => $profile->description,
                'capability_class' => $profile->capability_class,
                'status' => $profile->status,
                'system_prompt_template' => $profile->system_prompt_template,
                'budget_config' => $profile->budget_config,
                'model_config' => $profile->model_config,
                'tool_definition_count' => $profile->tool_definitions_count,
                'run_count' => $profile->runs_count,
            ])
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * List tool definitions granted to a specific agent profile.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  string                 $profile        Profile ID or slug
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Profile info with granted tool definitions
     */
    public function profileToolDefinitions(Request $request, string $profile, AgenticAuthorization $authorization): AppResponse
    {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedProfile = AgentProfile::query()
            ->when(
                ctype_digit($profile),
                static fn ($query) => $query->where(function ($profileQuery) use ($profile) {
                    $profileQuery->whereKey((int) $profile)
                        ->orWhere('slug', $profile);
                }),
                static fn ($query) => $query->where('slug', $profile)
            )
            ->first();

        if (! $resolvedProfile) {
            return ires()->notFound(__('mgs.agentic.profile-not-found'));
        }

        $resolvedProfile->load([
            'toolDefinitions' => fn ($query) => $query
                ->withCount('bindings')
                ->orderBy('tool_name'),
        ]);

        return ires()->data([
            'profile' => [
                'id' => $resolvedProfile->id,
                'name' => $resolvedProfile->name,
                'slug' => $resolvedProfile->slug,
                'capability_class' => $resolvedProfile->capability_class,
                'status' => $resolvedProfile->status,
            ],
            'grants' => $resolvedProfile->toolDefinitions->map(fn (AgentToolDefinition $definition) => [
                'id' => $definition->id,
                'tool_name' => $definition->tool_name,
                'tool_family' => $definition->tool_family,
                'contract_steward' => $definition->contract_steward,
                'capability_class' => $definition->capability_class,
                'status' => $definition->status,
                'binding_count' => $definition->bindings_count,
                'grant' => [
                    'enabled' => (bool) $definition->pivot?->enabled,
                    'safety_override' => $definition->pivot?->safety_override,
                ],
            ])->values(),
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function pagination(Request $request, int $defaultPerPage = 50): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(500, max(1, (int) $request->input('per_page', $defaultPerPage)));

        return [$page, $perPage];
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    /**
     * Convert a mixed value to a nullable boolean.
     *
     * @param  mixed  $value  The value to convert
     * @return bool|null  The boolean value or null if empty/unresolvable
     */
    protected function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
