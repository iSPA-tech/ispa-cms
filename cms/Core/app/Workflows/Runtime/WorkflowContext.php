<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Workflows\Runtime\Exceptions\InvalidWorkflowContextException;
use Cms\Core\Workflows\Runtime\Exceptions\WorkflowSerializationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Holds the full execution context for a running workflow instance.
 *
 * Encapsulates the root Eloquent model, trigger metadata, runtime variables, and per-step
 * state. Supports serialization to/from arrays and JSON for context snapshot persistence.
 *
 * @see WorkflowTrigger
 * @see StepState
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowContext
{
    /**
     * Variable key reserved for executor-owned runtime data (instance id, current step, queued flag).
     *
     * Only {@see setRuntime()} writes it; a stored snapshot keeps it through {@see fromArray()}.
     */
    public const RUNTIME_KEY = '__runtime';

    protected Model $root;

    protected WorkflowTrigger $trigger;

    /**
     * @var array<string, mixed>
     */
    protected array $vars = [];

    /**
     * @var array<string, StepState>
     */
    protected array $steps = [];

    /**
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $vars
     * @param array<string, StepState|array> $steps
     * @param  Model $root
     */
    public function __construct(Model $root, array $trigger = [], array $vars = [], array $steps = [])
    {
        $this->root = $root;
        $this->trigger = $this->normalizeTrigger($trigger);
        $this->vars = $vars;
        $this->steps = $this->normalizeSteps($steps);
    }

    /**
     * Get workflow root model.
     * @return Model
     */
    public function getRoot(): Model
    {
        return $this->root;
    }

    /**
     * Set a variable in context.
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function setVar(string $key, mixed $value): void
    {
        $this->guardRuntimePath($key);
        $this->vars[$key] = $value;
    }

    /**
     * Get a variable from context.
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    public function getVar(string $key, mixed $default = null): mixed
    {
        return $this->vars[$key] ?? $default;
    }

    /**
     * Check variable existence.
     * @param  string $key
     * @return bool
     */
    public function hasVar(string $key): bool
    {
        return Arr::has($this->vars, $key);
    }

    /**
     * Record a step result.
     * @param  string $stepCode
     * @param  mixed $result
     * @param  string $status
     * @param  array $meta
     * @return void
     */
    public function setStepResult(string $stepCode, mixed $result, string $status = 'success', array $meta = []): void
    {
        $state = $this->steps[$stepCode] ?? new StepState($stepCode);
        $state->setResult($result);
        $state->setStatus($status);
        $state->setMeta($meta);

        $this->steps[$stepCode] = $state;
    }

    /**
     * Get a step state by code.
     * @param  string $stepCode
     * @return StepState|null
     */
    public function getStep(string $stepCode): ?StepState
    {
        return $this->steps[$stepCode] ?? null;
    }

    /**
     * @return array<string, StepState>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVars(): array
    {
        return $this->vars;
    }

    /**
     * Replace all vars (used by runners that remove keys).
     *
     * The payload must carry `__runtime` exactly as the context holds it (present with the same
     * value, or absent on both sides); any other payload is refused so a step can neither
     * overwrite nor drop the executor-owned runtime vars.
     *
     * @param array<string,mixed> $vars
     * @return void
     *
     * @throws InvalidWorkflowContextException When the payload changes or drops `__runtime`.
     */
    public function setVars(array $vars): void
    {
        $hasCurrent = array_key_exists(self::RUNTIME_KEY, $this->vars);
        $hasNew = array_key_exists(self::RUNTIME_KEY, $vars);

        if ($hasCurrent !== $hasNew || ($hasCurrent && $vars[self::RUNTIME_KEY] !== $this->vars[self::RUNTIME_KEY])) {
            throw $this->runtimeWriteRefused(self::RUNTIME_KEY);
        }

        $this->vars = $vars;
    }

    /**
     * Set a nested variable using dot notation (e.g. customer.name).
     * @param  string $path
     * @param  mixed $value
     * @return void
     *
     * @throws InvalidWorkflowContextException When the path targets `__runtime` or below it.
     */
    public function setVarPath(string $path, mixed $value): void
    {
        $this->guardRuntimePath($path);
        Arr::set($this->vars, $path, $value);
    }

    /**
     * Write one executor-owned runtime variable (`vars.__runtime.<key>`).
     *
     * This is the only writer of `__runtime`; it is meant for the workflow executor and the
     * ad-hoc dispatcher, never for step runners.
     *
     * @param  string $key   Runtime variable name (e.g. instance_id, queued).
     * @param  mixed  $value Value to store.
     * @return void
     */
    public function setRuntime(string $key, mixed $value): void
    {
        $runtime = is_array($this->vars[self::RUNTIME_KEY] ?? null) ? $this->vars[self::RUNTIME_KEY] : [];
        $runtime[$key] = $value;
        $this->vars[self::RUNTIME_KEY] = $runtime;
    }

    /**
     * Check whether a dot-notation variable path is `__runtime` or lies below it.
     *
     * Surrounding whitespace on the first segment is ignored so `" __runtime.x"` also counts.
     *
     * @param  string $path Variable path without the `vars.` prefix.
     * @return bool True when the path targets the executor-owned runtime vars.
     */
    public static function isRuntimePath(string $path): bool
    {
        return trim(explode('.', $path, 2)[0]) === self::RUNTIME_KEY;
    }

    /**
     * Get a nested variable using dot notation.
     * @param  string $path
     * @param  mixed $default
     * @return mixed
     */
    public function getVarPath(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->vars, $path, $default);
    }

    /**
     * Get the workflow trigger metadata.
     *
     * @return WorkflowTrigger The trigger that initiated this workflow.
     */
    public function getTrigger(): WorkflowTrigger
    {
        return $this->trigger;
    }

    /**
     * Get a step result by code.
     * @param  string $stepCode
     * @param  mixed $default
     * @return mixed
     */
    public function getStepResult(string $stepCode, mixed $default = null): mixed
    {
        $state = $this->steps[$stepCode] ?? null;

        return $state instanceof StepState ? $state->getResult() : $default;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WorkflowSerializationException When the context cannot be serialized.
     */
    public function toArray(): array
    {
        try {
            $visited = [];
            $rootSnapshot = $this->snapshotModel($this->root, 0, $visited);

            return [
                'root' => $rootSnapshot,
                'vars' => $this->snapshotValue($this->vars, $visited),
                'steps' => collect($this->steps)->map(
                    fn(StepState $step) => $this->snapshotValue($step->toArray(), $visited)
                )->all(),
                'trigger' => $this->trigger->toArray(),
            ];
        } catch (\Throwable $e) {
            throw new WorkflowSerializationException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Serialize the context to a JSON string.
     *
     * @return string The JSON-encoded context.
     *
     * @throws WorkflowSerializationException If JSON encoding fails.
     */
    public function toJson(): string
    {
        $encoded = json_encode($this->toArray());

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new WorkflowSerializationException('Failed to encode WorkflowContext to JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * Reconstruct a WorkflowContext from a serialized array snapshot.
     *
     * @param  array<string, mixed> $data The serialized context data.
     * @param  Model                $root The root Eloquent model for the workflow.
     * @return self A new WorkflowContext instance.
     */
    public static function fromArray(array $data, Model $root): self
    {
        $trigger = $data['trigger'] ?? [];
        $vars = $data['vars'] ?? [];
        $stepsRaw = $data['steps'] ?? [];

        $steps = [];
        foreach ($stepsRaw as $code => $step) {
            if ($step instanceof StepState) {
                $steps[$code] = $step;
                continue;
            }

            if (!is_array($step)) {
                continue;
            }

            $steps[$code] = new StepState(
                $step['code'] ?? $code,
                $step['status'] ?? 'pending',
                $step['result'] ?? null,
                $step['meta'] ?? [],
            );
        }

        return new self($root, $trigger, $vars, $steps);
    }

    /**
     * Refuse a write to the executor-owned runtime vars.
     *
     * @param  string $path Variable path without the `vars.` prefix.
     * @return void
     *
     * @throws InvalidWorkflowContextException When the path targets `__runtime`.
     */
    protected function guardRuntimePath(string $path): void
    {
        if (self::isRuntimePath($path)) {
            throw $this->runtimeWriteRefused($path);
        }
    }

    /**
     * Build the exception for a refused runtime write.
     *
     * @param  string $path Variable path the step tried to write.
     * @return InvalidWorkflowContextException
     */
    protected function runtimeWriteRefused(string $path): InvalidWorkflowContextException
    {
        return new InvalidWorkflowContextException(
            "Workflow variable [vars.{$path}] is reserved for the workflow executor and may not be written by a step."
        );
    }

    /**
     * Create a normalized trigger object.
     *
     * @param array<string, mixed> $trigger
     */
    protected function normalizeTrigger(array $trigger): WorkflowTrigger
    {
        $event = (string) ($trigger['event'] ?? '');
        $at = $trigger['at'] ?? null;
        $meta = is_array($trigger['meta'] ?? null) ? $trigger['meta'] : [];

        return new WorkflowTrigger($event, $at, $meta);
    }

    /**
     * @param array<string, StepState|array> $steps
     * @return array<string, StepState>
     */
    protected function normalizeSteps(array $steps): array
    {
        $normalized = [];
        foreach ($steps as $code => $step) {
            if ($step instanceof StepState) {
                $normalized[$code] = $step;
                continue;
            }

            if (!is_array($step)) {
                continue;
            }

            $normalized[$code] = new StepState(
                $step['code'] ?? (string) $code,
                $step['status'] ?? 'pending',
                $step['result'] ?? null,
                $step['meta'] ?? [],
            );
        }

        return $normalized;
    }

    /**
     * Safely snapshot a model with limited relations.
     *
     * @param array<string, bool> $visited
     * @return array<string, mixed>
     */
    protected function snapshotModel(Model $model, int $depth, array &$visited, int $maxDepth = 2): array
    {
        $key = $model::class . ':' . $model->getKey();

        if (isset($visited[$key])) {
            return ['__ref' => $key];
        }

        $visited[$key] = true;

        $snapshot = [
            '__class' => $model::class,
            '__id' => $model->getKey(),
            'attributes' => $model->attributesToArray(),
            'relations' => [],
        ];

        if ($depth >= $maxDepth) {
            return $snapshot;
        }

        foreach ($model->getRelations() as $name => $relation) {
            if ($relation instanceof Model) {
                $snapshot['relations'][$name] = $this->snapshotModel($relation, $depth + 1, $visited, $maxDepth);
                continue;
            }

            if ($relation instanceof Collection) {
                $snapshot['relations'][$name] = $relation->map(
                    fn(Model $related) => $this->snapshotModel($related, $depth + 1, $visited, $maxDepth)
                )->all();
            }
        }

        return $snapshot;
    }

    protected function snapshotValue(mixed $value, array &$visited): mixed
    {
        if ($value instanceof Model) {
            return $this->snapshotModel($value, 0, $visited);
        }

        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->snapshotValue($item, $visited))->all();
        }

        if (is_array($value)) {
            $snapshot = [];
            foreach ($value as $key => $item) {
                $snapshot[$key] = $this->snapshotValue($item, $visited);
            }

            return $snapshot;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return $this->snapshotValue($value->jsonSerialize(), $visited);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            /** @var mixed $arrayable */
            $arrayable = $value->toArray();

            return $this->snapshotValue($arrayable, $visited);
        }

        if (is_object($value)) {
            return [
                '__class' => $value::class,
                '__string' => method_exists($value, '__toString') ? (string) $value : null,
            ];
        }

        return $value;
    }
}
