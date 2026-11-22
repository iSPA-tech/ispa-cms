<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Bulk\BulkRegistry;
use Cms\Core\Services\Bulk\BulkResult;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatch bulk operations on CMS resources.
 *
 * Routes incoming bulk action requests to the appropriate registered handler.
 * Supports dry-run previews, authorization checks, and validation before
 * executing batch operations across modules. Extends AdminController.
 *
 * @see BulkRegistry
 * @see BulkResult
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BulkController extends AdminController
{
    /**
     * Initialize the controller with the bulk handler registry.
     *
     * @param  BulkRegistry  $registry  Bulk handler registry
     */
    public function __construct(protected BulkRegistry $registry)
    {
        parent::__construct();
    }

    /**
     * Dispatch a bulk action to the registered handler for the given module/resource/action.
     *
     * @param  Request  $request   The incoming HTTP request
     * @param  string   $module    Module name
     * @param  string   $resource  Resource type
     * @param  string   $action    Action to perform
     * @return mixed  Bulk operation result or error response
     *
     * @throws AuthorizationException When the user lacks permission for the bulk action.
     */
    public function dispatch(Request $request, string $module, string $resource, string $action): mixed
    {
        $key = sprintf('%s::%s.%s', $module, $resource, $action);

        if (!$this->registry->has($key)) {
            return ires()->notFound('Bulk handler not registered.');
        }

        try {
            $handler = $this->registry->resolve($key);
        } catch (Throwable $exception) {
            Log::error('Failed to resolve bulk handler.', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return ires()->error()->message($exception->getMessage());
        }

        if (!$handler) {
            return ires()->notFound('Bulk handler not registered.');
        }

        $rules = array_merge($this->baseRules(), $handler->rules());
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ires()
                ->error()
                ->code(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->data(['errors' => $validator->errors()->all()])
                ->message('Validation failed');
        }

        $payload = $validator->validated();
        $dryRun = (bool)($payload['dry_run'] ?? false);

        try {
            $authorized = $handler->authorize($request->user(), $payload);
            if ($authorized === false) {
                throw new AuthorizationException('Forbidden');
            }
        } catch (AuthorizationException $exception) {
            $message = $exception->getMessage() ?: __('mgs.no.permission');

            return ires()->forbidden($message);
        }

        try {
            $result = $handler->handle($payload, $dryRun);
        } catch (Throwable $exception) {
            Log::error('Bulk handler execution failed.', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return ires()->error()->message($exception->getMessage());
        }

        if ($result instanceof BulkResult) {
            $result = $result->toArray();
        }

        if ($dryRun) {
            $eligibleIds = $result['eligible_ids'] ?? ($result['ids'] ?? []);
            $affectedCount = $result['affected_count']
                ?? ($result['affected'] ?? count($eligibleIds));

            $result['preview'] = true;
            $result['requested_ids'] = $payload['ids'];
            $result['eligible_ids'] = $eligibleIds;
            $result['affected_count'] = $affectedCount;
        }

        return ires()->data($result);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:20'],
            'ids.*' => ['integer'],
            'changes' => ['sometimes', 'array'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
