<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Runs a module-contributed node (#[WorkflowNode] service method).
 *
 * Before any side effect: the type must be a registered module node, its service class must still sit in an
 * allowlisted namespace (`core.workflow_nodes.allowlisted_namespaces`), the user the run acts for must hold the
 * node's permission, and the config (after `x-expr` fields are evaluated) must match the node's schema. The method
 * gets `(array $config, WorkflowContext $context)` and must return an array, stored under `storeAs` (`vars.*`), or a
 * {@see NodeResult} when it spent money, so the run budget counts the usage.
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-23
 */
class ModuleNodeRunner implements StepRunner
{
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine(),
        protected JsonSchemaShapeValidator $validator = new JsonSchemaShapeValidator(),
    ) {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $type = $step->stepType;
        $definition = NodeRegistry::current()->get($type);

        if ($definition === null || !$definition->isModuleNode()) {
            return StepRunResult::failed("{$type}: not a registered module node");
        }

        $class = (string) $definition->service;
        $method = (string) $definition->method;
        if (!NodeRegistry::isAllowlistedNamespace($class) || !method_exists($class, $method)) {
            return StepRunResult::failed("{$type}: class not allowed");
        }

        if ($definition->permission !== null && !$this->delegateMay($definition->permission, $context)) {
            return StepRunResult::failed("{$type}: the user this run acts for lacks permission {$definition->permission}");
        }

        $config = $step->stepConfig ?? [];
        $storeAs = $config['storeAs'] ?? null;
        if ($storeAs !== null && (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')
            || WorkflowContext::isRuntimePath(substr($storeAs, 5)))) {
            return StepRunResult::failed("{$type}: storeAs must start with vars. and may not write __runtime");
        }

        try {
            $config = $this->evaluateExpressions(NodeConfigValidator::stripReserved($config), $definition->configSchema, $context);
        } catch (Throwable $e) {
            return StepRunResult::failed("{$type}: expression failed: " . $e->getMessage());
        }

        $errors = NodeConfigValidator::schemaErrors($definition->configSchema, $config, $this->validator);
        if ($errors !== []) {
            return StepRunResult::failed("{$type}: invalid config: " . implode(' ', $errors));
        }

        try {
            $result = app()->call([app($class), $method], ['config' => $config, 'context' => $context]);
        } catch (Throwable $e) {
            return StepRunResult::failed("{$type}: " . $e->getMessage());
        }

        $usage = null;
        if ($result instanceof NodeResult) {
            $usage = $result->usage;
            $result = $result->data;
        }

        if (!is_array($result)) {
            return StepRunResult::failed("{$type}: the node must return an array");
        }

        if (is_string($storeAs)) {
            $context->setVarPath(substr($storeAs, 5), $result);
        }

        $this->traceWrite($definition, $step, $config, $result, $context);

        $run = StepRunResult::success($result, [['level' => 'info', 'message' => "{$type} ran", 'class' => $class, 'method' => $method]]);

        return $usage !== null && $usage !== [] ? $run->withUsage($usage) : $run;
    }

    /**
     * Trace a module node that writes or publishes (safety `internal_write` / `publish`) to its run (guardrails B9).
     * The record is named by the node type and the first `*_uuid` / `uuid` / `id` of the node's result; the fields are
     * the result's `applied_fields` when the node reports them, else the result keys. Values are never recorded.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $result
     */
    protected function traceWrite(NodeDefinition $definition, StepDefinition $step, array $config, array $result, WorkflowContext $context): void
    {
        if (array_intersect($definition->safetyFor($config), ['internal_write', 'publish']) === []) {
            return;
        }

        $key = null;
        foreach ($result as $name => $value) {
            if (is_string($name) && (str_ends_with($name, '_uuid') || $name === 'uuid' || $name === 'id') && (is_string($value) || is_int($value))) {
                $key = $value;
                break;
            }
        }
        $fields = is_array($result['applied_fields'] ?? null) ? $result['applied_fields'] : array_keys($result);

        WorkflowAudit::recordWritten($context, $step->stepCode, $definition->type, $key, array_values(array_filter($fields, 'is_string')), 'module_node');
    }

    protected function delegateMay(string $permission, WorkflowContext $context): bool
    {
        $instanceId = $context->getVarPath('__runtime.instance_id');
        $instance = $instanceId ? WorkflowInstance::query()->find($instanceId) : null;
        $user = $instance?->delegatedBy;

        return $user !== null && Gate::forUser($user)->allows($permission);
    }

    /**
     * Evaluate top-level config fields the schema marks `x-expr: true` (string values only).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function evaluateExpressions(array $config, array $schema, WorkflowContext $context): array
    {
        foreach ((array) ($schema['properties'] ?? []) as $key => $property) {
            if (is_array($property) && ($property['x-expr'] ?? false) === true && is_string($config[$key] ?? null)) {
                $config[$key] = $this->engine->evaluate($config[$key], $context, true);
            }
        }

        return $config;
    }
}
