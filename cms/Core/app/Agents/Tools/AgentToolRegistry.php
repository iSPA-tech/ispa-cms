<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Tools;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Agents\Support\AgentToolBindingData;
use Cms\Core\Agents\Support\AgentToolDefinitionData;

/**
 * Central registry for agent tool definitions and their bindings.
 *
 * Provides in-memory storage, service-class discovery (via reflection and the
 * AgentTool attribute), and database persistence for tool definitions and
 * bindings. The registry is the single source of truth for which tools are
 * available to agent profiles and how they are dispatched.
 *
 * @package Cms\Core\Agents\Tools
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentToolRegistry
{
    /** @var array<string, AgentToolDefinitionData> */
    protected array $definitions = [];

    /** @var array<string, array<int, AgentToolBindingData>> */
    protected array $bindings = [];

    /**
     * Create a new AgentToolRegistry instance.
     *
     * @param Container  $container The service container for resolving tool callables.
     * @param Repository $config    The configuration repository for discovery settings.
     */
    public function __construct(
        protected Container $container,
        protected Repository $config,
    ) {
    }

    /**
     * Register a tool definition in the in-memory registry.
     *
     * @param  AgentToolDefinitionData $definition The tool definition to register.
     * @return void
     */
    public function registerDefinition(AgentToolDefinitionData $definition): void
    {
        $this->definitions[$definition->toolName] = $definition;
    }

    /**
     * Register a tool binding in the in-memory registry.
     *
     * Selection identity is toolName + moduleScope + environment + tenantId + policyScope + bindingType. A binding
     * identical to a registered one is a no-op, so repeated discovery never grows this singleton; a binding with the
     * same identity but another target, rollout scope, config, enabled flag or priority throws and changes nothing,
     * so an ambiguous target never wins silently.
     *
     * @param  AgentToolBindingData $binding The tool binding to register.
     * @return void
     *
     * @throws LogicException If a different binding with the same selection identity is already registered.
     */
    public function registerBinding(AgentToolBindingData $binding): void
    {
        foreach ($this->bindings[$binding->toolName] ?? [] as $existing) {
            if ($this->selectionIdentity($existing) !== $this->selectionIdentity($binding)) {
                continue;
            }

            if ($existing->toArray() === $binding->toArray()) {
                return;
            }

            throw new LogicException("Conflicting binding for agent tool {$binding->toolName}.");
        }

        $this->bindings[$binding->toolName] ??= [];
        $this->bindings[$binding->toolName][] = $binding;
    }

    /** @return array<int, string|null> */
    protected function selectionIdentity(AgentToolBindingData $binding): array
    {
        return [
            $binding->toolName,
            $binding->moduleScope,
            $binding->environment,
            $binding->tenantId,
            $binding->policyScope,
            $binding->bindingType,
        ];
    }

    /**
     * Retrieve a tool definition by its canonical name.
     *
     * @param  string $toolName The canonical tool name.
     * @return AgentToolDefinitionData|null The definition, or null if not registered.
     */
    public function definition(string $toolName): ?AgentToolDefinitionData
    {
        return $this->definitions[$toolName] ?? null;
    }

    /**
     * Retrieve all registered tool definitions.
     *
     * @return array<string, AgentToolDefinitionData> Keyed by tool name.
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Retrieve all registered bindings for a given tool.
     *
     * @param  string $toolName The canonical tool name.
     * @return array<int, AgentToolBindingData> The bindings for the tool.
     */
    public function bindingsFor(string $toolName): array
    {
        return $this->bindings[$toolName] ?? [];
    }

    /**
     * Check whether a tool definition exists in the registry.
     *
     * @param  string $toolName The canonical tool name.
     * @return bool True if the definition is registered.
     */
    public function hasDefinition(string $toolName): bool
    {
        return isset($this->definitions[$toolName]);
    }

    /**
     * Discover and register tool definitions and bindings from a service class.
     *
     * Scans public methods of the given class for AgentTool attributes or
     * methods ending with the configured suffix. Registers discovered
     * definitions and bindings in the in-memory registry.
     *
     * @param  string      $className   The fully-qualified service class to scan.
     * @param  string|null $moduleScope Optional module scope override.
     * @param  string|null $environment Optional environment override.
     * @return array<int, string> List of discovered tool names.
     *
     * @throws InvalidArgumentException If the class does not exist or is not in the allowlisted namespaces.
     */
    public function discoverService(
        string $className,
        ?string $moduleScope = null,
        ?string $environment = null,
    ): array {
        $this->guardNamespace($className);

        if (!class_exists($className)) {
            throw new InvalidArgumentException("Tool discovery class does not exist: {$className}");
        }

        $refClass = new ReflectionClass($className);
        if ($refClass->isAbstract()) {
            return [];
        }

        $discovered = [];
        $resolvedModuleScope = $moduleScope ?? $this->inferModuleScope($className);
        $suffix = (string) $this->config->get('core.agentic.discovery.method_suffix', '_tool');
        $requireAttribute = (bool) $this->config->get('core.agentic.discovery.require_attribute', false);
        $defaultEnvironment = $environment ?? $this->config->get('core.agentic.discovery.default_environment');

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->class !== $className) {
                continue;
            }

            $attribute = $this->resolveAttribute($method);
            $hasSuffix = Str::endsWith($method->getName(), $suffix);
            if (!$attribute && (!$hasSuffix || $requireAttribute)) {
                continue;
            }

            $toolName = $attribute?->name
                ?? $this->fallbackToolName($resolvedModuleScope, $method->getName(), $suffix);

            $definition = $attribute
                ? AgentToolDefinitionData::fromAttribute($toolName, $attribute)
                : new AgentToolDefinitionData(
                    toolName: $toolName,
                    toolFamily: $resolvedModuleScope,
                    contractSteward: $resolvedModuleScope,
                );

            $binding = new AgentToolBindingData(
                toolName: $toolName,
                moduleScope: $attribute?->moduleScope ?? $resolvedModuleScope,
                environment: $defaultEnvironment,
                bindingType: 'service_method',
                bindingTarget: $className . '@' . $method->getName(),
            );

            // Binding first: a conflicting binding throws before the definition is replaced.
            $this->registerBinding($binding);
            $this->registerDefinition($definition);
            $discovered[] = $toolName;
        }

        return $discovered;
    }

    /**
     * Resolve a binding into a PHP callable via the service container.
     *
     * @param  AgentToolBindingData $binding The binding to resolve.
     * @return callable The resolved callable ([instance, method]).
     *
     * @throws InvalidArgumentException If the binding type is unsupported or target is malformed.
     */
    public function resolveCallable(AgentToolBindingData $binding): callable
    {
        if ($binding->bindingType !== 'service_method' || !str_contains($binding->bindingTarget, '@')) {
            throw new InvalidArgumentException("Unsupported binding target: {$binding->bindingTarget}");
        }

        [$className, $method] = explode('@', $binding->bindingTarget, 2);
        $this->guardNamespace($className);
        $instance = $this->container->make($className);

        return [$instance, $method];
    }

    /**
     * Persist a tool definition to the database via updateOrCreate.
     *
     * @param  string $toolName The canonical tool name to persist.
     * @return AgentToolDefinition|null The persisted Eloquent model, or null if not registered.
     */
    public function persistDefinition(string $toolName): ?AgentToolDefinition
    {
        $definition = $this->definition($toolName);
        if (!$definition) {
            return null;
        }

        return AgentToolDefinition::query()->updateOrCreate(
            ['tool_name' => $definition->toolName],
            Arr::except($definition->toArray(), ['tool_name'])
        );
    }

    /**
     * Persist all bindings for a tool to the database via updateOrCreate.
     *
     * Also persists the parent definition if not already present.
     *
     * @param  string $toolName The canonical tool name whose bindings to persist.
     * @return array<int, AgentToolBinding> The persisted Eloquent binding models.
     */
    public function persistBindings(string $toolName): array
    {
        $definition = $this->persistDefinition($toolName);
        if (!$definition) {
            return [];
        }

        $persisted = [];
        foreach ($this->bindingsFor($toolName) as $binding) {
            $persisted[] = AgentToolBinding::query()->updateOrCreate(
                [
                    'tool_definition_id' => $definition->id,
                    'module_scope' => $binding->moduleScope,
                    'environment' => $binding->environment,
                    'tenant_id' => $binding->tenantId,
                    'policy_scope' => $binding->policyScope,
                    'binding_type' => $binding->bindingType,
                    'binding_target' => $binding->bindingTarget,
                ],
                [
                    'rollout_scope' => $binding->rolloutScope,
                    'binding_config' => $binding->bindingConfig,
                    'enabled' => $binding->enabled,
                    'priority' => $binding->priority,
                ]
            );
        }

        return $persisted;
    }

    /**
     * Discover tools from a service class and persist them to the database.
     *
     * Combines {@see discoverService()} with {@see persistBindings()} for
     * a one-step discovery-and-sync workflow.
     *
     * @param  string      $className   The fully-qualified service class to scan.
     * @param  string|null $moduleScope Optional module scope override.
     * @param  string|null $environment Optional environment override.
     * @return array<int, string> List of discovered and persisted tool names.
     */
    public function syncDiscoveredService(
        string $className,
        ?string $moduleScope = null,
        ?string $environment = null,
    ): array {
        $discovered = $this->discoverService($className, $moduleScope, $environment);

        foreach ($discovered as $toolName) {
            $this->persistBindings($toolName);
        }

        return $discovered;
    }

    /**
     * Resolve the AgentTool attribute from a reflected method, if present.
     *
     * @param  ReflectionMethod $method The reflected method to inspect.
     * @return AgentTool|null The attribute instance, or null if not annotated.
     */
    protected function resolveAttribute(ReflectionMethod $method): ?AgentTool
    {
        $attributes = $method->getAttributes(AgentTool::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Infer the module scope from a fully-qualified class name.
     *
     * Extracts the second namespace segment and converts it to snake_case.
     * Falls back to "core" if the namespace has fewer than two segments.
     *
     * @param  string $className The fully-qualified class name.
     * @return string The inferred module scope.
     */
    protected function inferModuleScope(string $className): string
    {
        $segments = explode('\\', trim($className, '\\'));

        return isset($segments[1]) ? Str::snake($segments[1]) : 'core';
    }

    /**
     * Generate a fallback tool name from the module scope and method name.
     *
     * Strips the configured suffix and produces a "module.snake_method" name.
     *
     * @param  string $moduleScope The module scope prefix.
     * @param  string $methodName  The method name (with suffix).
     * @param  string $suffix      The suffix to strip.
     * @return string The generated tool name (e.g. "core.my_action").
     */
    protected function fallbackToolName(string $moduleScope, string $methodName, string $suffix): string
    {
        $baseName = Str::before($methodName, $suffix);

        return Str::of($moduleScope)
            ->lower()
            ->append('.')
            ->append(Str::snake($baseName))
            ->toString();
    }

    /**
     * Guard that the class namespace is in the configured allowlist.
     *
     * If the allowlist is empty, all namespaces are permitted. Otherwise,
     * the class must start with one of the allowlisted namespace prefixes.
     *
     * @param  string $className The fully-qualified class name to check.
     * @return void
     *
     * @throws InvalidArgumentException If the class is not in an allowed namespace.
     */
    protected function guardNamespace(string $className): void
    {
        $allowlist = (array) $this->config->get('core.agentic.discovery.allowlisted_namespaces', []);
        if ($allowlist === []) {
            return;
        }

        foreach ($allowlist as $namespace) {
            if (Str::startsWith($className, $namespace)) {
                return;
            }
        }

        throw new InvalidArgumentException("Tool discovery is not allowed for class {$className}");
    }
}
