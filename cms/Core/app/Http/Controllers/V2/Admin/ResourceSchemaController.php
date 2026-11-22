<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Nwidart\Modules\Facades\Module;

/**
 * Serve the per-user schema of a declarative admin resource.
 *
 * @see ResourceRegistry
 * @see SchemaBuilder
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 */
class ResourceSchemaController extends Controller
{
    /**
     * Return the schema of one registered resource for the current user.
     *
     * Responds 404 when the resource is unknown or its module is disabled, 403 when the
     * user lacks the view ability, and 304 when the client already holds the current schema.
     *
     * @param  Request           $request   The incoming HTTP request
     * @param  string            $module    Module segment of the resource key
     * @param  string            $resource  Resource segment of the resource key
     * @return JsonResponse|Response  Schema payload or an error/not-modified response
     */
    public function show(Request $request, string $module, string $resource): JsonResponse|Response
    {
        $registry = app(ResourceRegistry::class);
        $key = $module . '/' . $resource;

        if (!$registry->has($key) || !$this->moduleEnabled($module)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $user = $request->user();
        $definition = $registry->make($key);

        if (!$user || !Gate::forUser($user)->allows($definition->ability())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $schema = app(SchemaBuilder::class)->build($definition, $user);

        $response = response()->json(['data' => $schema]);
        $response->setEtag(sha1((string) json_encode($schema)));
        $response->setPrivate();
        // max-age=0 keeps the global site-layout cache middleware from rewriting this to no-store.
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Whether the module matching the name or alias exists and is enabled.
     *
     * @param  string  $module  Module name or alias (case-insensitive)
     * @return bool
     */
    private function moduleEnabled(string $module): bool
    {
        $needle = strtolower($module);

        foreach (Module::all() as $candidate) {
            if (strtolower($candidate->getName()) === $needle || strtolower((string) $candidate->get('alias')) === $needle) {
                return $candidate->isEnabled();
            }
        }

        return false;
    }
}
