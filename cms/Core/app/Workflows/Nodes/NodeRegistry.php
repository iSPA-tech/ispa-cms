<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

use Cms\Core\Workflows\Nodes\Attributes\WorkflowNode;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Every workflow node type this site knows: one NodeDefinition per type.
 *
 * Sources, in order:
 * - every runner key of `core.workflow.step_runners` (described by `core.workflow_nodes.definitions.<type>`, or a
 *   permissive generic definition when no row exists, so a new runner never becomes unsavable);
 * - module nodes: #[WorkflowNode] methods on the services listed in `core.workflow_nodes.module_services`, each class
 *   inside `core.workflow_nodes.allowlisted_namespaces`. A module registers its service from its provider's
 *   `app()->booted()` with NodeRegistry::registerModuleService().
 *
 * A module node that cannot be registered (bad type name, invalid definition such as an unknown safety class, or a type
 * already registered) is dropped ON ITS OWN: the other nodes of the same service still register, the dropped node is
 * never replaced by a generic one (a workflow that uses it fails as an unknown step type), and it is listed by
 * rejected() with its class, method, type and reason. `cms:workflow-integrity-check` prints that list and fails.
 * A whole service is dropped only when the class itself is refused (not allowlisted, missing).
 *
 * The state is a pure function of that config and the code, so it is memoised per config fingerprint.
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-23
 */
class NodeRegistry
{
    /** @var array<string, array{0: array<string, NodeDefinition>, 1: list<array{class: string, method: string|null, type: string|null, reason: string}>}> */
    private static array $memo = [];

    /** @var array<string, NodeDefinition> */
    private array $definitions;

    /** @var list<array{class: string, method: string|null, type: string|null, reason: string}> */
    private array $rejected;

    public function __construct()
    {
        $fingerprint = md5((string) json_encode([
            self::runnerMap(),
            config('core.workflow_nodes', []),
        ]));

        [$this->definitions, $this->rejected] = self::$memo[$fingerprint] ??= $this->build();
    }

    /**
     * Module nodes (or whole services) that could not be registered, with the reason.
     *
     * @return list<array{class: string, method: string|null, type: string|null, reason: string}>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public static function current(): self
    {
        return new self();
    }

    /**
     * Runner classes by step type: `core.workflow.step_runners` plus the `step_runners` of every node set file in
     * `cms/Core/config/workflow_nodes/<set>.php` (merged as `core.workflow_nodes.<set>`), so node groups can be added in
     * their own files. A type already in `core.workflow.step_runners` keeps that runner.
     *
     * @return array<string, string>
     */
    public static function runnerMap(): array
    {
        $map = (array) config('core.workflow.step_runners', []);
        foreach (self::nodeSets() as $set) {
            foreach ((array) ($set['step_runners'] ?? []) as $type => $runner) {
                if (is_string($type) && is_string($runner) && !isset($map[$type])) {
                    $map[$type] = $runner;
                }
            }
        }

        return $map;
    }

    /**
     * Definition rows by step type: `core.workflow_nodes.definitions` plus the `definitions` of every node set file.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitionRows(): array
    {
        $rows = (array) config('core.workflow_nodes.definitions', []);
        foreach (self::nodeSets() as $set) {
            foreach ((array) ($set['definitions'] ?? []) as $type => $row) {
                if (is_string($type) && is_array($row) && !isset($rows[$type])) {
                    $rows[$type] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nodeSets(): array
    {
        $sets = [];
        foreach ((array) config('core.workflow_nodes', []) as $key => $value) {
            if (is_string($key) && is_array($value) && (isset($value['step_runners']) || isset($value['definitions']))
                && $key !== 'definitions') {
                $sets[] = $value;
            }
        }

        return $sets;
    }

    /**
     * Register a module service whose #[WorkflowNode] methods become node types. Call it from the module provider's
     * `app()->booted()` callback. The service must sit in an allowlisted namespace; its node types run through
     * ModuleNodeRunner.
     */
    public static function registerModuleService(string $class): void
    {
        $class = ltrim($class, '\\');
        $services = (array) config('core.workflow_nodes.module_services', []);
        if (!in_array($class, $services, true)) {
            $services[] = $class;
            config(['core.workflow_nodes.module_services' => array_values($services)]);
        }

        $attach = static function (StepRunnerRegistry $runners): void {
            self::current()->attachModuleRunners($runners);
        };

        app()->afterResolving(StepRunnerRegistry::class, $attach);
        if (app()->resolved(StepRunnerRegistry::class)) {
            $attach(app(StepRunnerRegistry::class));
        }
    }

    /**
     * Point every module node type at ModuleNodeRunner.
     */
    public function attachModuleRunners(StepRunnerRegistry $runners): void
    {
        foreach ($this->definitions as $type => $definition) {
            if ($definition->isModuleNode() && !$runners->has($type)) {
                $runners->register($type, app(ModuleNodeRunner::class));
            }
        }
    }

    public function get(string $type): ?NodeDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return array<string, NodeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Palette groups, ordered.
     *
     * @return list<array{key: string, label_key: string|null, order: int}>
     */
    public function groups(): array
    {
        $configured = (array) config('core.workflow_nodes.groups', []);
        $groups = [];
        foreach ($configured as $key => $row) {
            if (is_string($key)) {
                $row = is_array($row) ? $row : [];
                $groups[$key] = ['key' => $key, 'label_key' => $row['label_key'] ?? null, 'order' => (int) ($row['order'] ?? 100)];
            }
        }

        foreach ($this->definitions as $definition) {
            $groups[$definition->group] ??= ['key' => $definition->group, 'label_key' => null, 'order' => 1000];
        }

        usort($groups, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $groups;
    }

    /**
     * Types that count as an approval for the taint lint.
     *
     * @return list<string>
     */
    public function approvalTypes(): array
    {
        return array_values(array_filter((array) config('core.workflow_nodes.approval_types', ['approval']), 'is_string'));
    }

    /**
     * @return array{0: array<string, NodeDefinition>, 1: list<array{class: string, method: string|null, type: string|null, reason: string}>}
     */
    private function build(): array
    {
        $definitions = [];
        $rejected = [];
        $rows = self::definitionRows();

        foreach (self::runnerMap() as $type => $runner) {
            if (!is_string($type) || $type === '' || !is_string($runner)) {
                continue;
            }

            try {
                $row = is_array($rows[$type] ?? null) ? $rows[$type] : ['palette' => false];
                $definitions[$type] = NodeDefinition::fromArray($type, $row, $runner);
            } catch (Throwable $e) {
                // A broken row never makes a runner unsavable: fall back to the permissive generic definition.
                Log::warning('Workflow node definition replaced by a generic one: ' . $e->getMessage());
                try {
                    $definitions[$type] = NodeDefinition::fromArray($type, ['palette' => false], $runner);
                } catch (Throwable) {
                }
            }
        }

        foreach ((array) config('core.workflow_nodes.module_services', []) as $class) {
            if (!is_string($class)) {
                continue;
            }

            try {
                $found = $this->discover($class, $dropped);
            } catch (Throwable $e) {
                // The class itself is refused (not allowlisted, missing): none of its nodes can register.
                $rejected[] = ['class' => ltrim($class, '\\'), 'method' => null, 'type' => null, 'reason' => $e->getMessage()];
                Log::warning('Workflow node discovery skipped ' . $class . ': ' . $e->getMessage());
                continue;
            }

            foreach ($dropped as $item) {
                $rejected[] = $item;
                Log::warning("Workflow node {$item['class']}::{$item['method']} dropped: {$item['reason']}");
            }
            foreach ($found as $definition) {
                if (isset($definitions[$definition->type])) {
                    // The first registration keeps the type; the later copy is dropped and reported.
                    $item = ['class' => (string) $definition->service, 'method' => $definition->method, 'type' => $definition->type, 'reason' => "Workflow node type {$definition->type} is already registered."];
                    $rejected[] = $item;
                    Log::warning("Workflow node {$item['class']}::{$item['method']} dropped: {$item['reason']}");
                    continue;
                }
                $definitions[$definition->type] = $definition;
            }
        }

        return [$definitions, $rejected];
    }

    /**
     * The valid #[WorkflowNode] methods of a module service. An invalid node is left out and described in `$dropped`
     * (its siblings are still returned); a refused class (not allowlisted, missing) throws.
     *
     * @param list<array{class: string, method: string|null, type: string|null, reason: string}>|null $dropped Set to the nodes left out.
     * @return list<NodeDefinition>
     */
    public function discover(string $class, ?array &$dropped = null): array
    {
        $dropped = [];
        $class = ltrim($class, '\\');
        if (!self::isAllowlistedNamespace($class)) {
            throw new InvalidArgumentException("Workflow node discovery is not allowed for class {$class}.");
        }
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Workflow node class does not exist: {$class}.");
        }

        $ref = new ReflectionClass($class);
        if ($ref->isAbstract()) {
            return [];
        }

        $found = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            foreach ($method->getAttributes(WorkflowNode::class) as $attribute) {
                $node = null;
                try {
                    /** @var WorkflowNode $node */
                    $node = $attribute->newInstance();
                    $found[] = $this->definitionOf($node, $class, $method->getName());
                } catch (Throwable $e) {
                    $dropped[] = ['class' => $class, 'method' => $method->getName(), 'type' => $node?->type, 'reason' => $e->getMessage()];
                }
            }
        }

        return $found;
    }

    /**
     * The definition of one #[WorkflowNode] method.
     *
     * @throws InvalidArgumentException When the type name or the definition is invalid.
     */
    private function definitionOf(WorkflowNode $node, string $class, string $method): NodeDefinition
    {
        if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $node->type)) {
            throw new InvalidArgumentException("Module workflow node {$node->type} must be named <module>.<name>.");
        }

        return new NodeDefinition(
            type: $node->type,
            version: $node->version,
            group: $node->group,
            labelKey: $node->label,
            descriptionKey: $node->description,
            safety: array_values($node->safety),
            approvalDefault: $node->approval,
            taintOutput: $node->taint,
            taintSink: $node->taintSink,
            permission: $node->permission,
            configSchema: $node->schema,
            defaultConfig: $node->defaults,
            outputs: array_values($node->outputs),
            bindings: array_values($node->bindings),
            upgrader: $node->upgrader,
            runner: ModuleNodeRunner::class,
            module: explode('.', $node->type)[0],
            service: $class,
            method: $method,
            icon: $node->icon,
            editor: $node->editor,
            result: $node->result,
            resultType: $node->resultType,
            dynamic: $node->dynamic,
        );
    }

    public static function isAllowlistedNamespace(string $class): bool
    {
        foreach ((array) config('core.workflow_nodes.allowlisted_namespaces', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_ends_with($prefix, '\\') && str_starts_with($class, ltrim($prefix, '\\'))) {
                return true;
            }
        }

        return false;
    }
}
