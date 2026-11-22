<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentProfileTool;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Agents\AgentToolDefinition;

/**
 * Provisions and manages agent profiles, tool grants, bindings, and schedules.
 *
 * Provides CRUD for agent profiles, synchronizes discovered tool services
 * into the registry, manages tool-to-profile grants with enable/safety
 * overrides, and seeds template profiles and starter schedules for
 * bootstrapping the agentic subsystem.
 *
 * @see AgentToolRegistry
 * @see AgentScheduleService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentToolProvisioner
{
    /**
     * Initialize the provisioner with tool registry, config, and schedule service.
     *
     * @param AgentToolRegistry    $registry        Registry for discovering and persisting agent tools.
     * @param ConfigRepository     $config          Application configuration repository.
     * @param AgentScheduleService $scheduleService Service for managing agent schedule CRUD.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected AgentToolRegistry $registry,
        protected ConfigRepository $config,
        protected AgentScheduleService $scheduleService,
    ) {
    }

    /**
     * Synchronize discovered tool services into the agent tool registry.
     *
     * @param array<int, string|array<string, mixed>> $services   Service class names or config arrays.
     * @param string|null                              $environment Target environment override.
     * @return array<int, array{class: string, module_scope: string|null, tool_names: array<int, string>}>
     */
    public function syncServices(array $services = [], ?string $environment = null): array
    {
        $resolvedServices = $services !== []
            ? $this->normalizeServices($services)
            : $this->normalizeServices((array) $this->config->get('core.agentic.bootstrap_services', []));

        $results = [];
        foreach ($resolvedServices as $service) {
            $toolNames = $this->registry->syncDiscoveredService(
                $service['class'],
                $service['module_scope'] ?? null,
                $environment ?? $this->defaultEnvironment()
            );

            $results[] = [
                'class' => $service['class'],
                'module_scope' => $service['module_scope'] ?? null,
                'tool_names' => $toolNames,
            ];
        }

        return $results;
    }

    /**
     * Create a new agent profile.
     *
     * @param array<string, mixed> $attributes Profile attributes.
     * @return AgentProfile The created profile.
     */
    public function createProfile(array $attributes): AgentProfile
    {
        return AgentProfile::query()->create(Arr::only($attributes, [
            'name',
            'slug',
            'description',
            'capability_class',
            'status',
            'system_prompt_template',
            'model_config',
            'budget_config',
        ]))->fresh();
    }

    /**
     * Seed template agent profiles, grant starter tools, and create starter schedules.
     *
     * @param User|null $creator Optional user to assign as schedule creator.
     * @return array{
     *     profiles: Collection<int, AgentProfile>,
     *     created: int,
     *     existing: int,
     *     synced_tools: int,
     *     grants: array<string, array<int, string>>,
     *     missing_tools: array<int, string>,
     *     schedules_created: int,
     *     schedules_existing: int,
     *     starter_selection: array{profile_slug: string|null, tool_name: string|null}
     * }
     */
    public function seedTemplateProfiles(?User $creator = null): array
    {
        $created = 0;
        $existing = 0;
        $profiles = collect();
        $availableServices = $this->availableBootstrapServicesForSeeding();
        $syncResults = $availableServices === []
            ? []
            : $this->syncServices($availableServices, $this->defaultEnvironment());

        foreach ($this->templateProfiles() as $template) {
            $profile = AgentProfile::query()
                ->where('slug', $template['slug'])
                ->first();

            if ($profile) {
                $existing++;
                $profiles->push($profile->fresh());
                continue;
            }

            $created++;
            $profiles->push($this->createProfile($template));
        }

        $profilesBySlug = $profiles
            ->mapWithKeys(fn (AgentProfile $profile) => [$profile->slug => $profile->fresh()])
            ->all();

        $definitionsByName = AgentToolDefinition::query()
            ->whereIn('tool_name', $this->starterToolNames())
            ->get()
            ->keyBy('tool_name');

        $grants = [];
        $missingTools = [];
        foreach ($this->starterGrantMap() as $profileSlug => $toolNames) {
            $profile = $profilesBySlug[$profileSlug] ?? null;
            if (! $profile) {
                continue;
            }

            $grantableToolNames = array_values(array_filter(
                $toolNames,
                fn (string $toolName) => $definitionsByName->has($toolName)
            ));

            if ($grantableToolNames !== []) {
                $grantResult = $this->grantToolsToProfile($profile->slug, $grantableToolNames);
                $grants[$profile->slug] = $grantResult['granted'];
            } else {
                $grants[$profile->slug] = [];
            }

            foreach ($toolNames as $toolName) {
                if (! $definitionsByName->has($toolName)) {
                    $missingTools[] = $toolName;
                }
            }
        }

        [$schedulesCreated, $schedulesExisting] = $this->seedStarterSchedules(
            $profilesBySlug,
            $definitionsByName,
            $creator
        );

        return [
            'profiles' => $profiles->values(),
            'created' => $created,
            'existing' => $existing,
            'synced_tools' => array_sum(array_map(
                fn (array $result) => count($result['tool_names'] ?? []),
                $syncResults
            )),
            'grants' => $grants,
            'missing_tools' => array_values(array_unique($missingTools)),
            'schedules_created' => $schedulesCreated,
            'schedules_existing' => $schedulesExisting,
            'starter_selection' => $this->starterSelection($definitionsByName),
        ];
    }

    /**
     * Update an existing agent profile.
     *
     * @param string               $profileKey Profile ID or slug.
     * @param array<string, mixed> $attributes Attributes to update.
     * @return AgentProfile The updated profile.
     *
     * @throws InvalidArgumentException When the agent profile is not found.
     */
    public function updateProfile(string $profileKey, array $attributes): AgentProfile
    {
        $profile = $this->resolveProfile($profileKey);
        if (! $profile) {
            throw new InvalidArgumentException("Agent profile [{$profileKey}] not found.");
        }

        $profile->fill(Arr::only($attributes, [
            'name',
            'slug',
            'description',
            'capability_class',
            'status',
            'system_prompt_template',
            'model_config',
            'budget_config',
        ]));
        $profile->save();

        return $profile->fresh();
    }

    /**
     * Grant tool definitions to an agent profile.
     *
     * @param string            $profileKey Profile ID or slug.
     * @param array<int, string> $toolNames  Tool names to grant.
     * @return array{profile: AgentProfile, granted: array<int, string>, missing: array<int, string>}
     *
     * @throws InvalidArgumentException When the agent profile is not found.
     */
    public function grantToolsToProfile(string $profileKey, array $toolNames): array
    {
        $profile = $this->resolveProfile($profileKey);
        if (!$profile) {
            throw new InvalidArgumentException("Agent profile [{$profileKey}] not found.");
        }

        $requested = array_values(array_unique(array_filter($toolNames, fn ($tool) => is_string($tool) && $tool !== '')));
        $definitions = AgentToolDefinition::query()
            ->whereIn('tool_name', $requested)
            ->get()
            ->keyBy('tool_name');

        $missing = array_values(array_diff($requested, $definitions->keys()->all()));
        if ($missing !== []) {
            return [
                'profile' => $profile,
                'granted' => [],
                'missing' => $missing,
            ];
        }

        $profile->toolDefinitions()->syncWithoutDetaching(
            $definitions->mapWithKeys(fn (AgentToolDefinition $definition) => [
                $definition->id => [
                    'enabled' => true,
                    'safety_override' => null,
                ],
            ])->all()
        );

        return [
            'profile' => $profile->fresh('toolDefinitions'),
            'granted' => $definitions->keys()->values()->all(),
            'missing' => [],
        ];
    }

    /**
     * Resolve an agent profile by ID or slug.
     *
     * @param string $profileKey Profile ID or slug.
     * @return AgentProfile|null The resolved profile, or null if not found.
     */
    public function resolveProfile(string $profileKey): ?AgentProfile
    {
        return AgentProfile::query()
            ->when(
                ctype_digit($profileKey),
                static fn ($query) => $query->where(function ($profileQuery) use ($profileKey) {
                    $profileQuery->whereKey((int) $profileKey)
                        ->orWhere('slug', $profileKey);
                }),
                static fn ($query) => $query->where('slug', $profileKey)
            )
            ->first();
    }

    /**
     * Resolve a tool definition by ID or tool_name.
     *
     * @param string $toolDefinitionKey Tool definition ID or tool_name.
     * @return AgentToolDefinition|null The resolved definition, or null if not found.
     */
    public function resolveToolDefinition(string $toolDefinitionKey): ?AgentToolDefinition
    {
        return AgentToolDefinition::query()
            ->when(
                ctype_digit($toolDefinitionKey),
                static fn ($query) => $query->where(function ($definitionQuery) use ($toolDefinitionKey) {
                    $definitionQuery->whereKey((int) $toolDefinitionKey)
                        ->orWhere('tool_name', $toolDefinitionKey);
                }),
                static fn ($query) => $query->where('tool_name', $toolDefinitionKey)
            )
            ->first();
    }

    /**
     * Resolve a tool binding by its numeric ID.
     *
     * @param string $bindingKey The binding ID.
     * @return AgentToolBinding|null The resolved binding, or null if not found.
     */
    public function resolveBinding(string $bindingKey): ?AgentToolBinding
    {
        if (! ctype_digit($bindingKey)) {
            return null;
        }

        return AgentToolBinding::query()
            ->with('definition')
            ->find((int) $bindingKey);
    }

    /**
     * Update a profile-to-tool grant's enabled state or safety override.
     *
     * @param string                                               $profileKey        Profile ID or slug.
     * @param string                                               $toolDefinitionKey Tool definition ID or tool_name.
     * @param array{enabled?: bool, safety_override?: string|null} $attributes        Grant attributes to update.
     * @return array{profile: AgentProfile, definition: AgentToolDefinition, grant: AgentProfileTool}
     *
     * @throws InvalidArgumentException When the profile, definition, or grant is not found.
     */
    public function updateProfileToolGrant(string $profileKey, string $toolDefinitionKey, array $attributes): array
    {
        $profile = $this->resolveProfile($profileKey);
        if (! $profile) {
            throw new InvalidArgumentException("Agent profile [{$profileKey}] not found.");
        }

        $definition = $this->resolveToolDefinition($toolDefinitionKey);
        if (! $definition) {
            throw new InvalidArgumentException("Tool definition [{$toolDefinitionKey}] not found.");
        }

        $grant = AgentProfileTool::query()
            ->where('agent_profile_id', $profile->getKey())
            ->where('tool_definition_id', $definition->getKey())
            ->first();

        if (! $grant) {
            throw new InvalidArgumentException('Agent tool grant not found.');
        }

        if (array_key_exists('enabled', $attributes)) {
            $grant->enabled = (bool) $attributes['enabled'];
        }

        if (array_key_exists('safety_override', $attributes)) {
            $grant->safety_override = $attributes['safety_override'];
        }

        $grant->save();

        return [
            'profile' => $profile->fresh('toolDefinitions'),
            'definition' => $definition->fresh(),
            'grant' => $grant->fresh(),
        ];
    }

    /**
     * Revoke a tool grant from an agent profile.
     *
     * @param string $profileKey        Profile ID or slug.
     * @param string $toolDefinitionKey Tool definition ID or tool_name.
     * @return array{profile: AgentProfile, definition: AgentToolDefinition}
     *
     * @throws InvalidArgumentException When the profile, definition, or grant is not found.
     */
    public function revokeProfileToolGrant(string $profileKey, string $toolDefinitionKey): array
    {
        $profile = $this->resolveProfile($profileKey);
        if (! $profile) {
            throw new InvalidArgumentException("Agent profile [{$profileKey}] not found.");
        }

        $definition = $this->resolveToolDefinition($toolDefinitionKey);
        if (! $definition) {
            throw new InvalidArgumentException("Tool definition [{$toolDefinitionKey}] not found.");
        }

        $deleted = AgentProfileTool::query()
            ->where('agent_profile_id', $profile->getKey())
            ->where('tool_definition_id', $definition->getKey())
            ->delete();

        if ($deleted < 1) {
            throw new InvalidArgumentException('Agent tool grant not found.');
        }

        return [
            'profile' => $profile->fresh('toolDefinitions'),
            'definition' => $definition->fresh(),
        ];
    }

    /**
     * Update a tool binding's enabled state or priority.
     *
     * @param string                                $bindingKey The binding ID.
     * @param array{enabled?: bool, priority?: int} $attributes Binding attributes to update.
     * @return array{binding: AgentToolBinding}
     *
     * @throws InvalidArgumentException When the tool binding is not found.
     */
    public function updateToolBinding(string $bindingKey, array $attributes): array
    {
        $binding = $this->resolveBinding($bindingKey);
        if (! $binding) {
            throw new InvalidArgumentException("Tool binding [{$bindingKey}] not found.");
        }

        if (array_key_exists('enabled', $attributes)) {
            $binding->enabled = (bool) $attributes['enabled'];
        }

        if (array_key_exists('priority', $attributes)) {
            $binding->priority = (int) $attributes['priority'];
        }

        $binding->save();

        return [
            'binding' => $binding->fresh('definition'),
        ];
    }

    protected function defaultEnvironment(): string
    {
        return (string) $this->config->get('core.agentic.discovery.default_environment', app()->environment());
    }

    /**
     * Return the default template profile definitions for seeding.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templateProfiles(): array
    {
        return [
            [
                'name' => 'Content Reader',
                'slug' => 'content-reader',
                'description' => 'Rà soát nội dung hiện có, tìm bài liên quan và chuẩn bị thông tin cho biên tập viên.',
                'capability_class' => 'class_0_read_only',
                'status' => 'active',
                'system_prompt_template' => 'Chi doc va tong hop. Uu tien tim bai lien quan, bai can cap nhat, va thong tin giup bien tap vien ra quyet dinh.',
                'model_config' => null,
                'budget_config' => ['max_steps' => 3],
            ],
            [
                'name' => 'Content Drafter',
                'slug' => 'content-drafter',
                'description' => 'Chuẩn bị bản nháp và đề cương nhanh để editor tinh chỉnh tiếp.',
                'capability_class' => 'class_1_draft_generation',
                'status' => 'active',
                'system_prompt_template' => 'Tap trung vao ban nhap ro rang, ngan gon, de sua. Khong tu publish hay tu ap dung thay doi.',
                'model_config' => ['driver' => 'openai', 'preset' => 'balanced'],
                'budget_config' => ['max_steps' => 5],
            ],
            [
                'name' => 'Content Editor',
                'slug' => 'content-editor',
                'description' => 'Tạo proposal tối ưu SEO và mô tả để editor duyệt trước khi apply.',
                'capability_class' => 'class_2_staged_mutation',
                'status' => 'active',
                'system_prompt_template' => 'Chi tao proposal co ly do ro rang, uu tien thay doi co diff de review, khong tu apply.',
                'model_config' => ['driver' => 'openai', 'preset' => 'quality'],
                'budget_config' => ['max_steps' => 8],
            ],
            [
                'name' => 'Ops Maintainer',
                'slug' => 'ops-maintainer',
                'description' => 'Chạy các lịch định kỳ an toàn như health overview và digest vận hành.',
                'capability_class' => 'class_3_autonomous_maintenance',
                'status' => 'active',
                'system_prompt_template' => 'Uu tien an toan van hanh, tong hop nhanh nhung co gia tri hanh dong, khong vuot budget.',
                'model_config' => ['driver' => 'openai', 'preset' => 'fast'],
                'budget_config' => ['max_steps' => 12],
            ],
            // Content answer pilot: seeded disabled; the fixed tool builds its own prompt and the
            // ephemeral path uses no persisted run budget, so no prompt template or budget is set.
            [
                'name' => 'Public Content Assistant',
                'slug' => 'public-content-assistant',
                'description' => 'Answers authenticated user questions from digest-approved Content projection snapshots.',
                'capability_class' => 'class_0_read_only',
                'status' => 'disabled',
                'system_prompt_template' => null,
                'model_config' => null,
                'budget_config' => null,
            ],
        ];
    }

    /**
     * Return the starter tool grant map keyed by profile slug.
     *
     * @return array<string, array<int, string>>
     */
    public function starterGrantMap(): array
    {
        $map = [
            'content-reader' => [
                'content.search_posts',
                'content.summarize_post',
                'content.suggest_internal_links',
            ],
            'content-drafter' => [
                'content.search_posts',
                'content.summarize_post',
            ],
            'content-editor' => [
                'content.propose_post_seo_refresh',
                'content.propose_post_description_refresh',
            ],
            'ops-maintainer' => [
                'core.agentic_health_overview',
                'product.inventory_alerts_summary',
            ],            'public-content-assistant' => [
                'content.answer_posts',
            ],
        ];

        // Starter tools a module adds to a profile, after the Core ones: `core.agentic.starter_grants`.
        foreach ((array) config('core.agentic.starter_grants', []) as $profile => $tools) {
            $map[$profile] = array_values(array_unique([...($map[$profile] ?? []), ...array_filter((array) $tools, 'is_string')]));
        }

        return $map;
    }

    /**
     * Return the starter schedule template definitions for seeding.
     *
     * @return array<int, array<string, mixed>>
     */
    public function starterScheduleTemplates(): array
    {
        return [
            [
                'name' => 'Daily agentic health overview',
                'description' => 'Paused starter schedule that summarizes failures, pending approvals, and schedule incidents each day.',
                'profile_slug' => 'ops-maintainer',
                'tool_name' => 'core.agentic_health_overview',
                'status' => 'paused',
                'cadence_minutes' => 1440,
                'tool_input' => [
                    'hours' => 24,
                    'limit' => 5,
                ],
            ],
            [
                'name' => 'Daily inventory alerts digest',
                'description' => 'Paused starter schedule that summarizes low-stock and out-of-stock product alerts each day.',
                'profile_slug' => 'ops-maintainer',
                'tool_name' => 'product.inventory_alerts_summary',
                'status' => 'paused',
                'cadence_minutes' => 1440,
                'tool_input' => [
                    'threshold' => 5,
                    'limit' => 10,
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function starterToolNames(): array
    {
        $toolNames = collect($this->starterGrantMap())->flatten(1);

        foreach ($this->starterScheduleTemplates() as $template) {
            if (is_string($template['tool_name'] ?? null) && $template['tool_name'] !== '') {
                $toolNames->push($template['tool_name']);
            }
        }

        return $toolNames->unique()->values()->all();
    }

    /**
     * @param array<string, AgentProfile> $profilesBySlug
     * @param \Illuminate\Support\Collection<string, AgentToolDefinition> $definitionsByName
     * @return array{0: int, 1: int}
     */
    protected function seedStarterSchedules(array $profilesBySlug, Collection $definitionsByName, ?User $creator = null): array
    {
        $created = 0;
        $existing = 0;

        foreach ($this->starterScheduleTemplates() as $template) {
            $profileSlug = (string) ($template['profile_slug'] ?? '');
            $toolName = (string) ($template['tool_name'] ?? '');
            $profile = $profilesBySlug[$profileSlug] ?? null;
            $definition = $definitionsByName->get($toolName);

            if (! $profile || ! $definition) {
                continue;
            }

            $alreadyExists = AgentSchedule::query()
                ->where('agent_profile_id', $profile->id)
                ->where('tool_definition_id', $definition->id)
                ->where('name', (string) $template['name'])
                ->exists();

            if ($alreadyExists) {
                $existing++;
                continue;
            }

            $this->scheduleService->create([
                'agent_profile_id' => $profile->id,
                'tool_definition_id' => $definition->id,
                'name' => (string) $template['name'],
                'description' => $template['description'] ?? null,
                'status' => $template['status'] ?? 'paused',
                'cadence_minutes' => (int) ($template['cadence_minutes'] ?? 1440),
                'tool_input' => is_array($template['tool_input'] ?? null) ? $template['tool_input'] : [],
            ], $creator);

            $created++;
        }

        return [$created, $existing];
    }

    /**
     * @param \Illuminate\Support\Collection<string, AgentToolDefinition> $definitionsByName
     * @return array{profile_slug: string|null, tool_name: string|null}
     */
    protected function starterSelection(Collection $definitionsByName): array
    {
        foreach ([
            ['profile_slug' => 'ops-maintainer', 'tool_name' => 'core.agentic_health_overview'],
            ['profile_slug' => 'ops-maintainer', 'tool_name' => 'product.inventory_alerts_summary'],
            ['profile_slug' => 'content-editor', 'tool_name' => 'content.propose_post_seo_refresh'],
            ['profile_slug' => 'content-reader', 'tool_name' => 'content.search_posts'],
        ] as $selection) {
            if ($definitionsByName->has($selection['tool_name'])) {
                return $selection;
            }
        }

        return [
            'profile_slug' => 'ops-maintainer',
            'tool_name' => null,
        ];
    }

    /**
     * @param array<int, string|array<string, mixed>> $services
     * @return array<int, array{class: string, module_scope: string|null}>
     */
    protected function normalizeServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (is_string($service) && $service !== '') {
                $normalized[] = [
                    'class' => $service,
                    'module_scope' => null,
                ];
                continue;
            }

            if (!is_array($service)) {
                continue;
            }

            $className = Arr::get($service, 'class');
            if (!is_string($className) || $className === '') {
                continue;
            }

            $moduleScope = Arr::get($service, 'module_scope');

            $normalized[] = [
                'class' => $className,
                'module_scope' => is_string($moduleScope) && $moduleScope !== '' ? $moduleScope : null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{class: string, module_scope: string|null}>
     */
    protected function availableBootstrapServicesForSeeding(): array
    {
        $services = $this->normalizeServices((array) $this->config->get('core.agentic.bootstrap_services', []));
        $available = [];

        foreach ($services as $service) {
            if (class_exists($service['class'])) {
                $available[] = $service;
                continue;
            }

            Log::warning('Skipping unavailable agentic bootstrap service during template seeding.', [
                'class' => $service['class'],
                'module_scope' => $service['module_scope'] ?? null,
            ]);
        }

        return $available;
    }
}
