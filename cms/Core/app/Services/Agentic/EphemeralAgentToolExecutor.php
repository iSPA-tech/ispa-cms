<?php

declare(strict_types=1);

namespace Cms\Core\Services\Agentic;

use App\Support\Modules\ModuleStatus;
use Closure;
use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Agents\Runtime\ToolExecutionTimeout;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Models\Agents\AgentProfile;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use JsonException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * Runs one granted, read-only, policy-authorized Agentic tool for a single request without persisting anything.
 *
 * It reuses AgentRunner::authorizeTool() and resolveBinding() with an in-memory AgentState whose `runId` is the
 * sentinel 0 (both methods are pure and never read it), and ToolExecutionTimeout for the time limit. No
 * WorkflowInstance, AgentRun, AgentRunStep, AgentToolCall or AgentArtifact row is created, so prompts and answers
 * never reach Agentic tables.
 *
 * Bound scoped (one instance per request or queue job). That does not make the AgentToolRegistry singleton
 * request-scoped: long-lived safety comes from the registry's duplicate rules (identical binding is a no-op,
 * conflicting binding throws) and from the module, profile and policy checks on every call.
 */
final class EphemeralAgentToolExecutor
{
    public const MAX_INPUT_BYTES = 16 * 1024;
    public const MAX_INPUT_DEPTH = 6;

    public function __construct(
        private readonly AgentToolRegistry $registry,
        private readonly AgentRunner $runner,
        private readonly Repository $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input Named tool arguments.
     * @return array<mixed>
     *
     * @throws EphemeralAgentToolException
     */
    public function execute(string $profileSlug, string $toolName, array $input, string $requiredModule): array
    {
        if (! ModuleStatus::isEnabled($requiredModule)) {
            throw new EphemeralAgentToolException('module_unavailable', 'Required module is unavailable.');
        }

        $profile = AgentProfile::query()
            ->with('toolDefinitions')
            ->where('slug', $profileSlug)
            ->where('status', 'active')
            ->first();
        if (! $profile) {
            throw new EphemeralAgentToolException('profile_unavailable', 'Agent profile is unavailable.');
        }

        $this->discoverBootstrapServices();
        $granted = $profile->toolDefinitions
            ->filter(static fn ($definition): bool => (bool) ($definition->pivot->enabled ?? true))
            ->pluck('tool_name')
            ->values()
            ->all();
        if (! in_array($toolName, $granted, true)) {
            throw new EphemeralAgentToolException('tool_not_granted', 'Agent tool is not granted.');
        }

        $definition = $this->registry->definition($toolName);
        if (! $definition || $definition->sideEffectClass !== 'read_only') {
            throw new EphemeralAgentToolException('tool_not_eligible', 'Agent tool is not eligible for ephemeral execution.');
        }

        $state = new AgentState(
            runId: 0,
            workflowInstanceId: null,
            parentWorkflowStepId: null,
            stepIndex: 1,
            environment: $this->environment(),
            tenantId: null,
            moduleScope: Str::before($toolName, '.'),
            goal: 'Serve one read-only user answer request.',
            availableToolDefinitions: $granted,
            profileCapabilityClass: $profile->capability_class,
        );

        $authorization = $this->runner->authorizeTool($state, $toolName);
        if (! $authorization->allowed || $authorization->requiresApproval) {
            throw new EphemeralAgentToolException('not_authorized', 'Agent tool is not authorized for ephemeral execution.');
        }

        $binding = $this->runner->resolveBinding($state, $toolName);
        if (! $binding) {
            throw new EphemeralAgentToolException('binding_unavailable', 'Agent tool binding is unavailable.');
        }

        try {
            $callable = $this->registry->resolveCallable($binding);
        } catch (Throwable) {
            throw new EphemeralAgentToolException('binding_unavailable', 'Agent tool binding is unavailable.');
        }

        $this->guardInput($callable, $input);

        try {
            $result = ToolExecutionTimeout::execute(
                static fn () => App::call($callable, $input),
                $definition->timeoutSeconds,
            );
        } catch (Throwable) {
            // No previous exception: tool messages may echo the request.
            throw new EphemeralAgentToolException('execution_failed', 'Agent tool execution failed.');
        }

        return is_array($result) ? $result : ['result' => $result];
    }

    /** Discover configured bootstrap services that exist; a broken entry never blocks the others. */
    private function discoverBootstrapServices(): void
    {
        foreach ((array) $this->config->get('core.agentic.bootstrap_services', []) as $service) {
            $class = is_array($service) ? (string) ($service['class'] ?? '') : (string) $service;
            $moduleScope = is_array($service) ? ($service['module_scope'] ?? null) : null;
            if ($class === '' || ! class_exists($class)) {
                continue;
            }

            try {
                $this->registry->discoverService($class, $moduleScope, $this->environment());
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function environment(): string
    {
        return (string) $this->config->get('core.agentic.discovery.default_environment', 'local');
    }

    /**
     * Generic dispatch guard: named arguments the callable declares, at most 16 KiB of JSON, at most six array
     * levels, no objects or resources. Domain validation stays in the tool.
     *
     * @param array<mixed> $input
     */
    private function guardInput(callable $callable, array $input): void
    {
        $parameters = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $this->reflect($callable)->getParameters(),
        );

        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! in_array($key, $parameters, true)) {
                throw new EphemeralAgentToolException('invalid_input', 'Agent tool input is not accepted.');
            }
        }

        if (! $this->plainValues($input, 1)) {
            throw new EphemeralAgentToolException('invalid_input', 'Agent tool input is not accepted.');
        }

        try {
            $bytes = strlen(json_encode($input, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new EphemeralAgentToolException('invalid_input', 'Agent tool input is not accepted.');
        }
        if ($bytes > self::MAX_INPUT_BYTES) {
            throw new EphemeralAgentToolException('invalid_input', 'Agent tool input is not accepted.');
        }
    }

    /** @param array<mixed> $values */
    private function plainValues(array $values, int $depth): bool
    {
        if ($depth > self::MAX_INPUT_DEPTH) {
            return false;
        }

        foreach ($values as $value) {
            if (is_object($value) || is_resource($value)) {
                return false;
            }
            if (is_array($value) && ! $this->plainValues($value, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private function reflect(callable $callable): ReflectionFunction|ReflectionMethod
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }
        if (is_string($callable) && str_contains($callable, '::')) {
            return new ReflectionMethod($callable);
        }

        return new ReflectionFunction(Closure::fromCallable($callable));
    }
}
