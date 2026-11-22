<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\Workflow;

use Throwable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use App\Http\Controllers\Controller;
use Cms\Core\Workflows\Schema\ModelSchemaRegistry;

/**
 * Expose Eloquent model schema information for the workflow builder.
 *
 * Provides endpoints to inspect model column definitions, relation schemas,
 * and available model types. Used by the workflow expression editor to
 * offer autocomplete and validation for model references.
 *
 * @see ModelSchemaRegistry
 * @package Cms\Core\Http\Controllers\Workflow
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ModelSchemaController extends Controller
{
    /**
     * Initialize the controller with the model schema registry.
     *
     * @param  ModelSchemaRegistry  $registry  Schema registry for Eloquent models
     */
    public function __construct(
        protected ModelSchemaRegistry $registry = new ModelSchemaRegistry()
    )
    {
        parent::__construct();
    }

    /**
     * Return the column schema for a given model class.
     *
     * @param  Request  $request  The incoming HTTP request (expects ?model=ClassName)
     * @return mixed  Model column schema or error
     */
    public function schema(Request $request)
    {
        $model = $request->query('model');

        if (!$model || !is_string($model)) {
            return ires()->data(['message' => 'model is required'], 422);
        }

        try {
            $schema = $this->registry->getSchema($model);
        } catch (InvalidArgumentException $e) {
            return ires()->data(['message' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            return ires()->data(['message' => $e->getMessage()], 400);
        }

        return ires()->data($schema);
    }

    /**
     * Return the schema for a specific relation on a model.
     *
     * @param  Request  $request  The incoming HTTP request (expects ?model=&relation=)
     * @return mixed  Relation schema or error
     */
    public function relation(Request $request)
    {
        $model = $request->query('model');
        $relation = $request->query('relation');

        if (!$model || !$relation || !is_string($model) || !is_string($relation)) {
            return ires()->data(['message' => 'model and relation are required'], 422);
        }

        try {
            $schema = $this->registry->getRelationSchema($model, $relation);
        } catch (InvalidArgumentException $e) {
            return ires()->data(['message' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            return ires()->data(['message' => $e->getMessage()], 400);
        }

        return ires()->data($schema);
    }

    /**
     * List all registered model types available for workflow expressions.
     *
     * @return mixed  Array of available model identifiers
     */
    public function models()
    {
        return ires()->data([
            'data' => $this->registry->listModels(),
        ]);
    }
}
