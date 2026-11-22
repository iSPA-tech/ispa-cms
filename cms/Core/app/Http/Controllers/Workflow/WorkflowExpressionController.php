<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\Workflow;

use Throwable;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Workflows\Runtime\RootModelLoader;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Http\Requests\Workflow\PreviewTemplateRequest;
use Cms\Core\Http\Requests\Workflow\ValidateExpressionRequest;
use Cms\Core\Workflows\Expression\Exceptions\TemplateRenderException;

/**
 * Validate and preview workflow expressions and templates.
 *
 * Provides endpoints for the workflow builder to validate expression syntax,
 * optionally evaluate against real database records, and preview rendered
 * template output. Supports both dry-run and database-backed evaluation modes.
 *
 * @see WorkflowExpressionEngine
 * @see TemplateRenderer
 * @package Cms\Core\Http\Controllers\Workflow
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowExpressionController extends Controller
{
    /**
     * Initialize the controller with expression engine, template renderer, and model loader.
     *
     * @param  WorkflowExpressionEngine  $engine    Expression validation and evaluation engine
     * @param  TemplateRenderer           $renderer  Template string renderer
     * @param  RootModelLoader            $loader    Model loader for database-backed previews
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine(),
        protected TemplateRenderer         $renderer = new TemplateRenderer(),
        protected RootModelLoader          $loader = new RootModelLoader(),
    )
    {
        parent::__construct();
    }

    /**
     * Validate a workflow expression and optionally evaluate it with sample data.
     *
     * @param  ValidateExpressionRequest  $request  Validated request with expression and options
     * @return mixed  Validation result with ok status and any errors
     */
    public function validateExpression(ValidateExpressionRequest $request)
    {
        $expression = $request->string('expression')->toString();
        $errors = $this->engine->validate($expression);

        $allowDb = (bool)$request->boolean('allowDb', false);
        $targetType = $request->input('targetType');
        $targetId = $request->has('targetId') ? (int)$request->input('targetId') : null;

        if ($allowDb && !$this->dbPreviewAllowed()) {
            return ires()->data([
                'ok' => false,
                'errors' => [['message' => 'Database-backed preview is not allowed']],
            ], 403);
        }

        if ($allowDb && !$errors) {
            try {
                $context = $this->makeContextFromRequest($request->validated(), $allowDb, $targetType, $targetId);
                // Try evaluation to catch runtime issues
                $this->engine->evaluate($expression, $context, true);
            } catch (Throwable $e) {
                $errors[] = ['message' => $e->getMessage()];
            }
        }

        return ires()->data([
            'ok' => empty($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * Check whether database-backed expression preview is allowed by configuration.
     *
     * @return bool  True if DB preview is enabled
     */
    protected function dbPreviewAllowed(): bool
    {
        return (bool)config('core.workflow.allow_expression_db_preview', false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function makeContextFromRequest(array $payload, bool $allowDb, ?string $targetType, ?int $targetId): WorkflowContext
    {
        $root = $this->makeDummyRoot();

        if ($allowDb && $targetType && $targetId !== null) {
            $root = $this->loader->load($targetType, $targetId, true);
        }

        $vars = $payload['sampleVars'] ?? [];
        // Runtime vars belong to the workflow executor; a preview request may not supply them.
        if (is_array($vars)) {
            unset($vars[WorkflowContext::RUNTIME_KEY]);
        }
        $steps = $payload['sampleSteps'] ?? [];
        $trigger = $payload['sampleTrigger'] ?? [];

        return new WorkflowContext($root, $trigger, $vars, $steps);
    }

    /**
     * Create a dummy Eloquent model for dry-run expression evaluation.
     *
     * @return Model  An anonymous model instance
     */
    protected function makeDummyRoot(): Model
    {
        return new class extends Model {
            protected $table = 'workflow_dummy';
            protected $guarded = [];
        };
    }

    /**
     * Render a workflow template string with sample or database-backed context.
     *
     * @param  PreviewTemplateRequest  $request  Validated request with template and options
     * @return mixed  Rendered output or error details
     */
    public function previewTemplate(PreviewTemplateRequest $request)
    {
        $allowDb = (bool)$request->boolean('allowDb', false);
        $targetType = $request->input('targetType');
        $targetId = $request->has('targetId') ? (int)$request->input('targetId') : null;

        if ($allowDb && !$this->dbPreviewAllowed()) {
            return ires()->data([
                'ok' => false,
                'errors' => [['message' => 'Database-backed preview is not allowed']],
            ], 403);
        }

        $context = $this->makeContextFromRequest($request->validated(), $allowDb, $targetType, $targetId);

        try {
            $rendered = $this->renderer->renderString($request->string('template')->toString(), $context);
            return ires()->data([
                'ok' => true,
                'rendered' => $rendered,
                'errors' => [],
            ]);
        } catch (TemplateRenderException $e) {
            return ires()->data([
                'ok' => false,
                'rendered' => null,
                'errors' => [['message' => $e->getMessage()]],
            ]);
        }
    }
}
