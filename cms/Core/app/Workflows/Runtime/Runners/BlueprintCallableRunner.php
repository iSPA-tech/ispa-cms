<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * Executes a blueprint-registered callable (service method, job, command, or event).
 *
 * Resolves arguments from the workflow context using expression evaluation, dispatches
 * the callable according to its type, and optionally stores the result in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BlueprintCallableRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for resolving callable arguments.
     *
     * @param WorkflowExpressionEngine $engine Expression engine for evaluating step config values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine()
    ) {
    }

    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $cfg = $step->stepConfig ?? [];
        $type = $cfg['callableType'] ?? null;
        $class = $cfg['class'] ?? null;
        $method = $cfg['method'] ?? null;
        $argsMapping = $cfg['argsMapping'] ?? [];
        $storeAs = $cfg['storeAs'] ?? null;

        if (!is_string($type) || !in_array($type, ['service', 'job', 'command', 'event'], true)) {
            return StepRunResult::failed('blueprint_callable: callableType must be service|job|command|event');
        }

        if (!is_string($class) || !class_exists($class)) {
            return StepRunResult::failed('blueprint_callable: invalid class');
        }

        // Validate storeAs before the callable runs so a bad config never causes the side effect.
        if (is_string($storeAs)) {
            if (!str_starts_with($storeAs, 'vars.')) {
                return StepRunResult::failed('blueprint_callable: storeAs must start with vars.');
            }
            $storePath = substr($storeAs, 5);
            if ($storePath === '__runtime' || str_starts_with($storePath, '__runtime.')) {
                return StepRunResult::failed('blueprint_callable: storeAs may not write __runtime');
            }
        }

        $logs = [
            ['level' => 'info', 'message' => "Executing {$type}", 'class' => $class, 'method' => $method],
        ];

        try {
            $result = null;
            $warnings = [];

            switch ($type) {
                case 'service':
                    if (!is_string($method) || $method === '') {
                        return StepRunResult::failed('blueprint_callable: method is required for service');
                    }
                    [$args, $warnings] = $this->resolveArgsForMethod(new \ReflectionMethod($class, $method), $argsMapping, $context);
                    $instance = app()->make($class);
                    $result = app()->call([$instance, $method], $args);
                    break;
                case 'job':
                    [$job, $warnings] = $this->makeJob($class, $argsMapping, $context);
                    $exec = $cfg['execution']['mode'] ?? 'sync';
                    $mode = $exec === 'async' ? 'async' : 'sync';
                    if ($mode === 'async') {
                        $dispatch = method_exists($job, 'dispatch') ? [$job, 'dispatch'] : null;
                        $queuedJob = $dispatch ? $job::dispatch() : Bus::dispatch($job);
                        if (!empty($cfg['execution']['queue'])) {
                            $queuedJob?->onQueue($cfg['execution']['queue']);
                        }
                        if (!empty($cfg['execution']['connection'])) {
                            $queuedJob?->onConnection($cfg['execution']['connection']);
                        }
                        if (!empty($cfg['execution']['delay'])) {
                            $queuedJob?->delay($cfg['execution']['delay']);
                        }
                        $result = ['mode' => 'async', 'queued' => true, 'class' => $class];
                    } else {
                        $syncResult = Bus::dispatchSync($job);
                        $result = ['mode' => 'sync', 'class' => $class, 'result' => $this->snapshot($syncResult)];
                    }
                    $logs[] = ['level' => 'info', 'message' => "job {$mode} dispatched"];
                    break;
                case 'command':
                    $args = is_array($argsMapping) ? $this->evaluateArgsMapping($argsMapping, $context) : [];
                    $exit = Artisan::call($class, $args ?? []);
                    $output = Artisan::output();
                    $result = ['exit_code' => $exit, 'output' => $output];
                    break;
                case 'event':
                    $args = is_array($argsMapping) ? $this->evaluateArgsMapping($argsMapping, $context) : [];
                    $ref = new ReflectionClass($class);
                    $event = $ref->newInstanceArgs(array_values($args));
                    Event::dispatch($event);
                    $result = ['dispatched' => true, 'class' => $class];
                    break;
            }

            foreach ($warnings as $warning) {
                $logs[] = ['level' => 'warning', 'message' => $warning];
            }

            if (is_string($storeAs)) {
                $context->setVarPath(substr($storeAs, 5), $this->snapshot($result));
            }

            return StepRunResult::success($this->snapshot($result), $logs);
        } catch (\Throwable $e) {
            Log::error('blueprint_callable failed', ['error' => $e->getMessage()]);
            return StepRunResult::failed('blueprint_callable: ' . $e->getMessage(), $logs, ['exception' => get_class($e)]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int,string>}
     *
     * @throws \InvalidArgumentException When a required argument mapping is missing.
     */
    protected function resolveArgsForMethod(ReflectionFunctionAbstract $method, array $mapping, WorkflowContext $context): array
    {
        $args = [];
        $warnings = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            $hasValue = array_key_exists($name, $mapping);

            if ($param->hasType() && $param->getType() instanceof \ReflectionNamedType) {
                $typeName = $param->getType()->getName();
                if (is_a($typeName, Request::class, true)) {
                    $warnings[] = "Parameter {$name} is Request; this may be unsafe.";
                }
            }

            if ($hasValue) {
                $expr = $mapping[$name];
                $args[$name] = $this->engine->evaluate($expr, $context, true);
                continue;
            }

            if ($name === 'model') {
                $args[$name] = $context->getRoot();
                continue;
            }

            if ($param->isOptional()) {
                continue;
            }

            throw new \InvalidArgumentException("Missing required argument mapping for {$name}");
        }

        return [$args, $warnings];
    }

    /**
     * @return array{0: object, 1: array<int,string>}
     */
    protected function makeJob(string $class, array $mapping, WorkflowContext $context): array
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor) {
            [$args, $warnings] = $this->resolveArgsForMethod($ctor, $mapping, $context);
            $job = $ref->newInstanceArgs(array_values($args));
            return [$job, $warnings];
        }
        return [new $class(), []];
    }

    /**
     * Evaluate args mapping without method reflection (for commands/events).
     *
     * @return array<string, mixed>
     */
    protected function evaluateArgsMapping(array $mapping, WorkflowContext $context): array
    {
        $args = [];
        foreach ($mapping as $key => $expr) {
            if (!is_string($expr)) {
                continue;
            }
            $args[$key] = $this->engine->evaluate($expr, $context, true);
        }

        return $args;
    }

    protected function snapshot(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return [
                '__class' => $value::class,
                '__id' => $value->getKey(),
                'attributes' => $value->getAttributes(),
            ];
        }

        if ($value instanceof Collection || $value instanceof EloquentCollection) {
            return $value->map(fn($v) => $this->snapshot($v))->all();
        }

        return $value;
    }
}
