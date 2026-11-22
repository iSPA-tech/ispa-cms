<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Expression;

use Cms\Core\Workflows\Expression\Exceptions\ExpressionEvaluationException;
use Cms\Core\Workflows\Expression\Exceptions\ExpressionValidationException;
use Cms\Core\Workflows\Runtime\StepState;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Evaluates and validates workflow expressions against a WorkflowContext.
 *
 * Wraps Symfony ExpressionLanguage with a sandboxed set of allowed functions (coalesce, len,
 * contains, in, now, formatDate), dot-path variable access, and security guards that prevent
 * object instantiation, static calls, and unauthorized method invocations.
 *
 * @see DotPathAccessor
 * @package Cms\Core\Workflows\Expression
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowExpressionEngine
{
    protected ExpressionLanguage $engine;

    protected DotPathAccessor $accessor;

    /**
     * @var array<string>
     */
    protected array $allowedFunctions = [
        'coalesce',
        'len',
        'contains',
        'in',
        'wf_contains',
        'wf_in',
        'now',
        'formatDate',
    ];

    /**
     * @param ExpressionLanguage|null $engine   Optional pre-configured expression language engine.
     * @param DotPathAccessor|null    $accessor Optional dot-path accessor for variable resolution.
     */
    public function __construct(?ExpressionLanguage $engine = null, ?DotPathAccessor $accessor = null)
    {
        $this->accessor = $accessor ?? new DotPathAccessor();
        $this->engine = $engine ?? new ExpressionLanguage();
        $this->registerFunctions();
    }

    /**
     * Evaluate an expression against the workflow context.
     *
     * @param  string              $expression     The expression string to evaluate.
     * @param  WorkflowContext     $context        The workflow context providing variables.
     * @param  bool                $strict         Whether to throw on evaluation failure.
     * @param  array<string,mixed> $extraVariables Additional variables to inject into the scope.
     * @return mixed The evaluation result.
     *
     * @throws ExpressionEvaluationException When the expression cannot be evaluated.
     */
    public function evaluate(string $expression, WorkflowContext $context, bool $strict = false, array $extraVariables = []): mixed
    {
        $expression = $this->normalizeFunctionNames($expression);
        $this->guardExpression($expression);

        $variables = array_merge($this->buildScope($context), $extraVariables);

        try {
            $result = $this->engine->evaluate($expression, $variables);

            return $this->unwrapAccessible($result);
        } catch (SyntaxError $e) {
            throw new ExpressionEvaluationException('Expression syntax error: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $fallback = $this->accessor->get($variables, $expression);

            if ($fallback !== null || !$strict) {
                return $this->unwrapAccessible($fallback);
            }

            throw new ExpressionEvaluationException('Expression evaluation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate expression syntax without executing it.
     *
     * @param  string $expression The expression string to validate.
     * @return array<int, array<string, mixed>> An array of validation errors, empty if valid.
     */
    public function validate(string $expression): array
    {
        $expression = $this->normalizeFunctionNames($expression);

        try {
            $this->guardExpression($expression);
        } catch (ExpressionValidationException $e) {
            return [['message' => $e->getMessage(), 'position' => $e->getPosition()]];
        }

        try {
            $this->engine->parse($expression, ['root', 'vars', 'steps', 'trigger']);
        } catch (SyntaxError $e) {
            $position = null;
            if (method_exists($e, 'getToken') && $e->getToken()) {
                $position = $e->getToken()->cursor ?? null;
            }
            return [['message' => $e->getMessage(), 'position' => $position]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildScope(WorkflowContext $context): array
    {
        $steps = [];
        foreach ($context->getSteps() as $code => $state) {
            $steps[$code] = $state instanceof StepState ? $state->toArray() : $state;
        }

        return [
            'root' => $context->getRoot(),
            'vars' => $this->makeAccessible($context->getVars()),
            'steps' => $this->makeAccessible($steps),
            'trigger' => $this->makeAccessible($context->getTrigger()->toArray()),
        ];
    }

    /**
     * Guard against disallowed syntax patterns in the expression.
     *
     * @param  string $expression The expression to validate.
     * @return void
     *
     * @throws ExpressionValidationException When the expression contains disallowed syntax.
     */
    protected function guardExpression(string $expression): void
    {
        if (str_contains($expression, 'new ') || str_contains($expression, '::')) {
            throw new ExpressionValidationException('Disallowed syntax detected');
        }

        if (preg_match('/\.\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/', $expression)) {
            throw new ExpressionValidationException('Method calls on objects are not allowed');
        }

        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $expression, $matches)) {
            foreach ($matches[1] as $fn) {
                if (!in_array($fn, $this->allowedFunctions, true)) {
                    throw new ExpressionValidationException("Function {$fn} is not allowed");
                }
            }
        }
    }

    /**
     * Normalize function names that conflict with ExpressionLanguage operators.
     */
    protected function normalizeFunctionNames(string $expression): string
    {
        $map = [
            'contains' => 'wf_contains',
            'in' => 'wf_in',
        ];

        return preg_replace_callback('/\\b(contains|in)\\s*\\(/', function ($matches) use ($map) {
            $fn = $matches[1];
            return ($map[$fn] ?? $fn) . '(';
        }, $expression);
    }

    protected function registerFunctions(): void
    {
        $this->engine->register(
            'coalesce',
            function (...$args) {
                $list = implode(', ', $args);
                return sprintf('(function(){foreach([%s] as $a){if($a !== null && $a !== "" && $a !== []) return $a;} return null;})()', $list);
            },
            fn(array $arguments, ...$args) => collect($args)->first(fn($a) => $a !== null && $a !== '' && $a !== []) ?? null,
        );

        $this->engine->register(
            'len',
            fn($value) => sprintf('(is_array(%1$s) || %1$s instanceof \\Countable) ? count(%1$s) : (is_string(%1$s) ? strlen(%1$s) : ((%1$s instanceof \\Illuminate\\Support\\Collection) ? %1$s->count() : null))', $value),
            function (array $arguments, $value) {
                if (is_array($value) || $value instanceof \Countable) {
                    return count($value);
                }
                if (is_string($value)) {
                    return strlen($value);
                }
                if ($value instanceof Collection) {
                    return $value->count();
                }
                return null;
            }
        );

        $this->engine->register(
            'contains',
            fn($str, $needle) => sprintf('str_contains((string)%s, (string)%s)', $str, $needle),
            fn(array $arguments, $str, $needle) => str_contains((string) $str, (string) $needle)
        );
        $this->engine->register(
            'wf_contains',
            fn($str, $needle) => sprintf('str_contains((string)%s, (string)%s)', $str, $needle),
            fn(array $arguments, $str, $needle) => str_contains((string) $str, (string) $needle)
        );

        $this->engine->register(
            'in',
            fn($value, $set) => sprintf('(is_array(%2$s) || %2$s instanceof \\Traversable) ? in_array(%1$s, %2$s) : false', $value, $set),
            function (array $arguments, $value, $set) {
                if ($set instanceof Collection) {
                    return $set->contains($value);
                }
                return is_array($set) ? in_array($value, $set, true) : false;
            }
        );
        $this->engine->register(
            'wf_in',
            fn($value, $set) => sprintf('(is_array(%2$s) || %2$s instanceof \\Traversable) ? in_array(%1$s, %2$s) : false', $value, $set),
            function (array $arguments, $value, $set) {
                if ($set instanceof Collection) {
                    return $set->contains($value);
                }
                return is_array($set) ? in_array($value, $set, true) : false;
            }
        );

        $this->engine->register(
            'now',
            fn() => '\Carbon\Carbon::now()',
            fn() => Carbon::now()
        );

        $this->engine->register(
            'formatDate',
            fn($dt, $fmt = "'Y-m-d H:i:s'") => sprintf('\Carbon\Carbon::parse(%s)->format(%s)', $dt, $fmt),
            function (array $arguments, $dt, $fmt = 'Y-m-d H:i:s') {
                if ($dt === null) {
                    return null;
                }

                return Carbon::parse($dt)->format($fmt);
            }
        );
    }

    /**
     * Recursively wrap arrays so ExpressionLanguage can access keys as properties.
     */
    protected function makeAccessible(mixed $value): mixed
    {
        if (is_array($value)) {
            $wrapped = array_map(fn($v) => $this->makeAccessible($v), $value);

            return new class ($wrapped) extends \ArrayObject {
                /**
                 * Initialize the accessible array wrapper with property-style access.
                 *
                 * @param array $data The wrapped array data.
                 *
                 * @author  iSPA Team
                 * @since   8.0.0 (2026-03-17)
                 */
                public function __construct(array $data)
                {
                    parent::__construct($data, \ArrayObject::ARRAY_AS_PROPS);
                }

                /**
                 * Retrieve the value at the given key, returning null instead of throwing if the key does not exist.
                 *
                 * @param mixed $key The array key to look up.
                 * @return mixed The value at the key, or null if not found.
                 *
                 * @author  iSPA Team
                 * @since   8.0.0 (2026-03-17)
                 */
                public function offsetGet($key): mixed
                {
                    return $this->offsetExists($key) ? parent::offsetGet($key) : null;
                }
            };
        }

        if ($value instanceof Collection) {
            return $value->map(fn($v) => $this->makeAccessible($v));
        }

        return $value;
    }

    /**
     * Convert ArrayObject wrappers back to native arrays for consumers.
     */
    protected function unwrapAccessible(mixed $value): mixed
    {
        if ($value instanceof \ArrayObject) {
            return array_map(fn($v) => $this->unwrapAccessible($v), $value->getArrayCopy());
        }

        if ($value instanceof Collection) {
            return $value->map(fn($v) => $this->unwrapAccessible($v))->all();
        }

        return $value;
    }
}
