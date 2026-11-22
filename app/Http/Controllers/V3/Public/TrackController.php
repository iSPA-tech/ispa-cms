<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Public;

use App\Constants\Constant;
use App\Events\SaveTracking;
use App\Http\Controllers\Controller;
use App\Http\Requests\V3\SaveTrackingRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * View tracking ingest for the headless frontend.
 *
 * The Laravel rendered site dispatches SaveTracking straight from HomeController,
 * but the Next.js frontend has no equivalent entry point, so page views from it
 * were never recorded. Page caching also rules out counting during render: an
 * ISR page renders once per revalidation window, not once per visit.
 */
class TrackController extends Controller
{
    public function store(SaveTrackingRequest $request): mixed
    {
        $alias = (string) $request->validated('target');

        if ($alias === SaveTrackingRequest::PAGE_TARGET) {
            return $this->storePageView($request);
        }

        $targetType = $this->targetClassFor($alias);
        $targetId = $this->resolveTargetId($request, $targetType);

        if ($targetType === null || $targetId === null || !$this->targetExists($targetType, $targetId)) {
            return ires()
                ->error()
                ->code(404)
                ->message(__('mgs.err.not_found'))
                ->data(['target' => $alias, 'id' => $targetId]);
        }

        //  Dispatched through the event so this path stays identical to the one
        //  HomeController uses. Whether the visit actually moves a counter is
        //  decided downstream by the throttle window and the daily dedupe
        //  claims, and SaveTrackingHandle returns void, so the outcome is
        //  deliberately not echoed back to the caller.
        event(new SaveTracking(
            [Constant::PAGE_VIEW, $alias],
            $targetType,
            $targetId,
            $request->validated('visitor')
        ));

        return ires()->data(['accepted' => true]);
    }

    /**
     * A page view with no backing entity. The reader facing url is rebuilt from
     * the configured frontend base, then pre-classified: paths outside the
     * public_path_patterns allowlist are rejected here so crawler junk cannot
     * reach the pipeline (an unclassifiable event would still move the site
     * counters) and cannot mint hash targets.
     */
    private function storePageView(SaveTrackingRequest $request): mixed
    {
        $path = (string) $request->validated('path');
        $publicUrl = rtrim((string) config('tracking.frontend_base_url'), '/') . $path;

        $target = app(\App\Services\Tracking\TrackingTargetKeyResolver::class)
            ->resolve(null, 0, null, [], $publicUrl);

        if ($target === null) {
            return ires()
                ->error()
                ->code(404)
                ->message(__('mgs.err.not_found'))
                ->data(['path' => $path]);
        }

        event(new SaveTracking(
            [Constant::PAGE_VIEW, SaveTrackingRequest::PAGE_TARGET],
            null,
            0,
            $request->validated('visitor'),
            $publicUrl
        ));

        return ires()->data(['accepted' => true]);
    }

    /**
     * The pipeline stores integer target ids, but some public payloads (a
     * catalog tree) expose only uuids. When a beacon addresses such an
     * entity by uuid, look the integer key up here.
     */
    private function resolveTargetId(SaveTrackingRequest $request, ?string $targetType): ?int
    {
        $explicitId = $request->validated('id');
        if ($explicitId !== null) {
            return (int) $explicitId;
        }

        $uuid = $request->validated('uuid');
        if ($uuid === null || $targetType === null || !is_subclass_of($targetType, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $targetType();
        $id = $model->newQuery()->where('uuid', (string) $uuid)->value($model->getKeyName());

        return $id !== null ? (int) $id : null;
    }

    /**
     * Map a public alias back to the model class the tracking pipeline expects.
     * Several classes may share one alias; they all resolve to the same target
     * key, so the first match is enough.
     */
    private function targetClassFor(string $alias): ?string
    {
        foreach ((array) config('tracking.target_aliases', []) as $class => $configuredAlias) {
            if ((string) $configuredAlias === $alias && class_exists((string) $class)) {
                return (string) $class;
            }
        }

        return null;
    }

    /**
     * Reject ids that point at nothing. Without this the endpoint would happily
     * build aggregate rows and leaderboard entries for targets that do not exist.
     */
    private function targetExists(string $targetType, int $targetId): bool
    {
        if (!is_subclass_of($targetType, Model::class)) {
            return false;
        }

        /** @var Model $model */
        $model = new $targetType();

        return $model->newQuery()->whereKey($targetId)->exists();
    }
}
