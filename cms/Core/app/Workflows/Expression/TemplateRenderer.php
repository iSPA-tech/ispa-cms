<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Expression;

use Cms\Core\Workflows\Expression\Exceptions\TemplateRenderException;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Renders template strings containing {{ expression }} placeholders against a workflow context.
 *
 * Supports rendering single strings, safe rendering with error suppression, and recursive
 * rendering of mixed data structures (arrays, objects) within the workflow engine.
 *
 * @see WorkflowExpressionEngine
 * @package Cms\Core\Workflows\Expression
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TemplateRenderer
{
    /**
     * Initialize the renderer with an expression engine for resolving placeholders.
     *
     * @param WorkflowExpressionEngine $engine Expression engine for evaluating template expressions.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine()
    ) {
    }

    /**
     * Render a string template containing {{ expression }} blocks.
     *
     * @param  string          $template The template string with {{ expression }} placeholders.
     * @param  WorkflowContext $context  The workflow context for expression evaluation.
     * @return string The rendered string with all expressions replaced by their values.
     *
     * @throws TemplateRenderException If any expression within the template fails to evaluate.
     */
    public function renderString(string $template, WorkflowContext $context): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function ($matches) use ($context, $template) {
            $expression = $matches[1] ?? '';
            try {
                $value = $this->engine->evaluate($expression, $context, false);
            } catch (\Throwable $e) {
                throw new TemplateRenderException($template, $expression, $e->getMessage(), $e);
            }

            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($value === null) {
                return '';
            }

            return (string) $value;
        }, $template);
    }

    /**
     * Render string but swallow errors, returning original template on failure.
     *
     * @param  string          $template The template string with {{ expression }} placeholders.
     * @param  WorkflowContext $context  The workflow context for expression evaluation.
     * @return string The rendered string, or the original template if rendering fails.
     */
    public function renderStringSafe(string $template, WorkflowContext $context): string
    {
        try {
            return $this->renderString($template, $context);
        } catch (TemplateRenderException $e) {
            // Optionally log here if needed
            return $template;
        }
    }

    /**
     * Recursively render any supported structure.
     *
     * @param  mixed           $value   The value to render (string, array, or object).
     * @param  WorkflowContext $context The workflow context for expression evaluation.
     * @return mixed The rendered value with all template expressions resolved.
     */
    public function renderMixed(mixed $value, WorkflowContext $context): mixed
    {
        if (is_string($value)) {
            return $this->renderString($value, $context);
        }

        if (is_array($value)) {
            return collect($value)->map(fn($item) => $this->renderMixed($item, $context))->all();
        }

        if (is_object($value)) {
            $props = [];
            foreach (get_object_vars($value) as $key => $val) {
                $props[$key] = $this->renderMixed($val, $context);
            }
            return $props;
        }

        return $value;
    }
}
