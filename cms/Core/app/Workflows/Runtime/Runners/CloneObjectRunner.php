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
use Cms\Core\Workflows\Security\WorkflowModelRefs;
use Illuminate\Database\Eloquent\Model;

/**
 * Executes a step that clones an Eloquent model and optionally persists the copy.
 *
 * Evaluates the source model from a context expression, replicates it with configurable
 * excluded attributes, and stores the clone in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CloneObjectRunner implements StepRunner
{
    use ResolvesModelTargets;

    /**
     * Initialize the runner with an expression engine for evaluating source model expressions.
     *
     * @param WorkflowExpressionEngine $engine Expression engine for evaluating step config values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine(),
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
        $sourceExpr = $cfg['sourceExpr'] ?? null;
        $exclude = $cfg['exclude'] ?? ['id', 'created_at', 'updated_at'];
        $save = (bool) ($cfg['save'] ?? false);
        $storeAs = $cfg['storeAs'] ?? null;

        if (!is_string($sourceExpr) || $sourceExpr === '') {
            return StepRunResult::failed('clone_object: sourceExpr is required');
        }

        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')) {
            return StepRunResult::failed('clone_object: storeAs must start with vars.');
        }

        // The source is a reference this run produced; the run's delegate must be allowed to create that model.
        $sources = $this->resolveModelTargets('clone_object', $sourceExpr, $cfg, $context, false, $error, static fn (): string => 'create');
        if ($sources === null || count($sources) !== 1) {
            return StepRunResult::failed($error ?? 'clone_object: sourceExpr must resolve to a model');
        }
        $source = $sources[0];

        // Hidden attributes (secrets, hashes, tokens) are never copied, whatever `exclude` says.
        $except = array_values(array_unique([
            ...(is_array($exclude) ? array_filter($exclude, 'is_string') : ['id', 'created_at', 'updated_at']),
            ...$source->getHidden(),
        ]));
        $clone = $source->replicate($except);
        $saved = false;
        $id = null;

        if ($save) {
            try {
                $clone->save();
                $saved = true;
                $id = $clone->getKey();
            } catch (\Throwable $e) {
                return StepRunResult::failed('clone_object: save failed: ' . $e->getMessage());
            }
        }

        // A snapshot with a signed reference, never the live clone; an unsaved clone stays in the run's working set.
        WorkflowModelRefs::remember($context, $clone);
        $context->setVarPath(substr($storeAs, 5), WorkflowModelRefs::snapshot($clone, $context));

        $logs = [[
            'action' => 'clone_object',
            'saved' => $saved,
            'excluded' => $except,
        ]];

        return StepRunResult::success([
            'cloned' => true,
            'saved' => $saved,
            'storedAs' => $storeAs,
            'id' => $id,
        ], $logs);
    }
}
