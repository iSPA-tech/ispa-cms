<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Cms\Core\Workflows\Security\WorkflowModelRefs;
use Illuminate\Database\Eloquent\Model;

/**
 * Target resolution shared by the write steps (`change`, `delete_object`, `commit_objects`, `clone_object`).
 *
 * A target is a model reference this run produced ({@see WorkflowModelRefs}): never read from the trigger payload
 * directly, of the step's `model` when the step names one, allowed by WorkflowCallableGuard, and the run's delegate
 * must be allowed the step's ability on every row before any of them is touched.
 *
 * @package Cms\Core\Workflows\Runtime\Runners
 * @since   2026-11-25
 */
trait ResolvesModelTargets
{
    /**
     * The models $expression points at, or null with $error set.
     *
     * @param array<string, mixed>              $config   Step config (`model` pins the target class).
     * @param (callable(Model): string)|null    $ability  Ability per row; default `update`, `create` for an unsaved model.
     * @return list<Model>|null
     */
    protected function resolveModelTargets(
        string $type,
        string $expression,
        array $config,
        WorkflowContext $context,
        bool $allowList,
        ?string &$error,
        ?callable $ability = null,
    ): ?array {
        $error = null;

        if (WorkflowModelRefs::readsTrigger($expression)) {
            $error = "{$type}: the target may not come from the trigger payload; load the record with retrieve first";

            return null;
        }

        try {
            $target = $this->engine->evaluate($expression, $context, true);
        } catch (\Throwable $e) {
            $error = "{$type}: failed to evaluate the target: " . $e->getMessage();

            return null;
        }

        [$models, $refError] = WorkflowModelRefs::resolve($context, $target, $allowList);
        if ($refError !== null) {
            $error = "{$type}: {$refError}";

            return null;
        }

        $pinned = is_string($config['model'] ?? null) ? ltrim(trim($config['model']), '\\') : '';
        $guard = app(WorkflowCallableGuard::class);
        $ability ??= static fn (Model $model): string => $model->exists ? 'update' : 'create';

        // Every row is checked before the step touches any of them.
        foreach ($models as $model) {
            if ($pinned !== '' && strcasecmp($pinned, $model::class) !== 0) {
                $error = "{$type}: target is not a {$pinned}";

                return null;
            }

            $wanted = $ability($model);
            $refusal = $guard->modelStepRefusal($context, $wanted, $model->exists && $wanted !== 'create' ? $model : $model::class);
            if ($refusal !== null) {
                $error = "{$type}: {$refusal}";

                return null;
            }
        }

        return $models;
    }
}
