<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Records\RecordHistory;
use Cms\Core\Records\RecordRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Serve the change history of one registered record.
 *
 * @see RecordRegistry
 * @see RecordHistory
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 */
class RecordHistoryController extends Controller
{
    /**
     * Return one page of a record's history, newest first.
     *
     * Responds 404 for an unknown type, 403 when the user lacks the type's view ability (a type-level
     * check that reveals nothing about the record), and 404 when the record does not exist or the
     * type's resolver hides it from this user, so record existence never leaks.
     *
     * @param  Request  $request  The incoming HTTP request (`per_page`, `before`)
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @return JsonResponse  `{data: items, meta: {next_before}}` or an error response
     */
    public function show(Request $request, string $type, string $uuid): JsonResponse
    {
        $recordType = app(RecordRegistry::class)->get($type);
        if ($recordType === null) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $user = $request->user();
        if (!$user || !Gate::forUser($user)->allows($recordType->viewAbility)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $record = $recordType->resolve($uuid, $user);
        if ($record === null) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $page = app(RecordHistory::class)->for(
            $recordType,
            $record,
            (int) ($validated['per_page'] ?? 20),
            isset($validated['before']) ? (int) $validated['before'] : null,
        );

        return response()->json([
            'data' => $page['items'],
            'meta' => ['next_before' => $page['next_before']],
        ]);
    }
}
