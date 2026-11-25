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
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
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

        // Deny by default: only allowlisted callables run (core.workflow_security). Checked on the class name before
        // it is autoloaded, made or dispatched.
        if (!is_string($class) || !app(WorkflowCallableGuard::class)->allowsCallable($type, $class, is_string($method) ? $method : null)) {
            return StepRunResult::failed('blueprint_callable: class not allowed');
        }

        // Commands may be given by artisan name; the guard already matched them against the registered commands.
        if ($type !== 'command' && !class_exists($class)) {
            return StepRunResult::failed('blueprint_callable: invalid class');
        }

        // An async job runs only on an allowlisted queue connection / queue.
        $execution = is_array($cfg['execution'] ?? null) ? $cfg['execution'] : [];
        $queue = is_string($execution['queue'] ?? null) ? $execution['queue'] : null;
        $connection = is_string($execution['connection'] ?? null) ? $execution['connection'] : null;
        if ($type === 'job' && !app(WorkflowCallableGuard::class)->allowsJobQueue($connection, $queue)) {
            return StepRunResult::failed('blueprint_callable: queue not allowed');
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
                    $exec = $execution['mode'] ?? 'sync';
                    $mode = $exec === 'async' ? 'async' : 'sync';
                    if ($mode === 'async') {
                        // Dispatch the job built with the mapped constructor arguments, configured before it is queued.
                        if ($queue !== null && $queue !== '' && method_exists($job, 'onQueue')) {
                            $job->onQueue($queue);
                        }
                        if ($connection !== null && $connection !== '' && method_exists($job, 'onConnection')) {
                            $job->onConnection($connection);
                        }
                        if (!empty($execution['delay']) && method_exists($job, 'delay')) {
                            $job->delay($execution['delay']);
                        }
                        Bus::dispatch($job);
                        $result = ['mode' => 'async', 'queued' => true, 'class' => $class];
                    } else {
                        $syncResult = Bus::dispatchSync($job);
                        $result = ['mode' => 'sync', 'class' => $class, 'result' => $this->snapshot($syncResult)];
                    }
                    $logs[] = ['level' => 'info', 'message' => "job {$mode} dispatched"];
                    break;
                case 'command':
                    $guard = app(WorkflowCallableGuard::class);
                    $commandName = (string) $guard->resolveCommandName($class);
                    $allowedOptions = $guard->allowedCommandOptions($commandName);
                    foreach (array_keys(is_array($argsMapping) ? $argsMapping : []) as $option) {
                        if (!in_array((string) $option, $allowedOptions, true)) {
                            return StepRunResult::failed("blueprint_callable: command option not allowed: {$option}", $logs);
                        }
                    }
                    $args = is_array($argsMapping) ? $this->evaluateArgsMapping($argsMapping, $context) : [];
                    $exit = Artisan::call($commandName, $args);
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
                $value = $this->engine->evaluate($expr, $context, true);

                // Expressions only yield model snapshots; an allowlisted callable that declares a model parameter
                // gets the row back from a reference this run produced (WorkflowModelRefs), never from a forged one.
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && is_a($type->getName(), \Illuminate\Database\Eloquent\Model::class, true) && is_array($value)) {
                    [$models] = \Cms\Core\Workflows\Security\WorkflowModelRefs::resolve($context, $value, false);
                    $value = count($models) === 1 && is_a($models[0], $type->getName()) ? $models[0] : null;
                }

                $args[$name] = $value;
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
            // Named arguments: a skipped optional parameter does not shift the ones after it.
            $job = $ref->newInstanceArgs($args);
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
                // attributesToArray() honours $hidden, so hidden fields never reach results or context.
                'attributes' => $value->attributesToArray(),
            ];
        }

        if ($value instanceof Collection || $value instanceof EloquentCollection) {
            return $value->map(fn($v) => $this->snapshot($v))->all();
        }

        return $value;
    }
}
