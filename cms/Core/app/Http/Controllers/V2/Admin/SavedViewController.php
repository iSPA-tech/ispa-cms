<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Cms\Core\Models\SavedView;
use Cms\Core\SavedViews\SavedViewRegistry;
use Cms\Core\SavedViews\SavedViewStateValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saved views of an admin list: named filter states per user, optionally shared with the list's users.
 *
 * Access rules:
 * - Everyone signed in to admin may keep private views (at most {@see self::MAX_PER_LIST} per list).
 * - Shared views are listed only to holders of the list's view ability.
 * - Only holders of the list's share ability, and Webmasters, may share, unshare or change a shared view, and they
 *   may delete any shared view of that list. A list without a share ability can be shared by Webmasters only.
 * - A user changes only their own views; another user's private view is always 404.
 *
 * Abilities are checked through the Gate, like every admin permission. Webmasters are recognised with
 * `User::isWebmaster()`, the same rule DataScope uses, because the global Gate bypass covers only the
 * permission and role resources.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 */
class SavedViewController extends Controller
{
    /** Most views one user keeps per list. */
    public const MAX_PER_LIST = 20;

    /** Most views returned by the index: the caller's own views plus shared ones. */
    public const MAX_LISTED = 40;

    /**
     * Create the controller.
     *
     * @param  SavedViewRegistry        $lists      Lists that accept saved views
     * @param  SavedViewStateValidator  $validator  Filter state checker
     */
    public function __construct(
        private readonly SavedViewRegistry $lists,
        private readonly SavedViewStateValidator $validator,
    ) {
    }

    /**
     * The caller's views of a list, followed by other users' shared views when the caller may view the list.
     *
     * @param  Request  $request  The incoming HTTP request (`list`)
     * @return JsonResponse  `{data: items}`, or 404 for an unknown list
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['list' => ['required', 'string', 'max:150']]);
        $abilities = $this->lists->resolve($validated['list']);
        if ($abilities === null) {
            return $this->notFound();
        }
        $user = $this->user($request);
        $canView = $this->canView($user, $abilities);
        $canShare = $this->canShare($user, $abilities);

        $query = SavedView::query()
            ->where('list_key', $validated['list'])
            ->where(static function ($query) use ($user, $canView): void {
                $query->where('user_id', $user->getKey());
                if ($canView) {
                    $query->orWhere('is_shared', true);
                }
            });

        // Own views first, then shared ones; ceiling = own cap plus as many shared views.
        $rows = $query
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->getKey()])
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MAX_LISTED)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SavedView $view): array => $this->item($view, $user, $canShare))->all(),
        ]);
    }

    /**
     * Save a new view of a list for the caller.
     *
     * @param  Request  $request  The incoming HTTP request (`list`, `name`, `state`, optional `is_shared`, `position`)
     * @return JsonResponse  201 `{data: item}`, 404 for an unknown list, 403 when sharing is not allowed, 422 when invalid or over the cap
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:80'],
            'state' => ['present'],
            'is_shared' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        $abilities = $this->lists->resolve($validated['list']);
        if ($abilities === null) {
            return $this->notFound();
        }
        $user = $this->user($request);
        $state = $this->validator->validate($request->input('state'));

        $shared = (bool) ($validated['is_shared'] ?? false);
        $canShare = $this->canShare($user, $abilities);
        if ($shared && !$canShare) {
            return $this->forbidden();
        }

        $count = SavedView::query()->where('user_id', $user->getKey())->where('list_key', $validated['list'])->count();
        if ($count >= self::MAX_PER_LIST) {
            throw ValidationException::withMessages(['list' => ['You can keep at most ' . self::MAX_PER_LIST . ' saved views per list.']]);
        }

        $view = SavedView::query()->create([
            'user_id' => $user->getKey(),
            'list_key' => $validated['list'],
            'name' => $validated['name'],
            'state' => $state,
            'is_shared' => $shared,
            'position' => (int) ($validated['position'] ?? $count),
        ]);

        return response()->json(['data' => $this->item($view, $user, $canShare)], 201);
    }

    /**
     * Rename a view, replace its state or move it. Only the owner may do so.
     *
     * Any change to a shared view, and sharing or unsharing, also needs the list's share ability, so only list admins
     * decide what other users see.
     *
     * @param  Request  $request  The incoming HTTP request (optional `name`, `state`, `is_shared`, `position`)
     * @param  string   $uuid     Saved view UUID
     * @return JsonResponse  `{data: item}`, 404 when not the caller's view, 403 when sharing is not allowed
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $user = $this->user($request);
        $view = SavedView::query()->where('uuid', $uuid)->where('user_id', $user->getKey())->first();
        if ($view === null) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'state' => ['sometimes', 'present'],
            'is_shared' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        if (array_key_exists('state', $validated)) {
            $validated['state'] = $this->validator->validate($request->input('state'));
        }

        $abilities = $this->lists->resolve($view->list_key);
        $canShare = $abilities !== null && $this->canShare($user, $abilities);
        $flips = array_key_exists('is_shared', $validated) && (bool) $validated['is_shared'] !== $view->is_shared;
        if (($flips || $view->is_shared) && !$canShare) {
            return $this->forbidden();
        }

        $view->fill($validated)->save();

        return response()->json(['data' => $this->item($view, $user, $canShare)]);
    }

    /**
     * Delete a view: the owner's own, or any shared view of the list for a holder of its share ability.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  string   $uuid     Saved view UUID
     * @return JsonResponse|Response  204, 404 when the caller may not see the view, 403 when they see but may not delete it
     */
    public function destroy(Request $request, string $uuid): JsonResponse|Response
    {
        $user = $this->user($request);
        $view = SavedView::query()->where('uuid', $uuid)->first();
        if ($view === null) {
            return $this->notFound();
        }

        if ((int) $view->user_id !== (int) $user->getKey()) {
            $abilities = $this->lists->resolve($view->list_key);
            if (!$view->is_shared || $abilities === null || !$this->canView($user, $abilities)) {
                return $this->notFound();
            }
            if (!$this->canShare($user, $abilities)) {
                return $this->forbidden();
            }
        }

        $view->delete();

        return response()->noContent();
    }

    /**
     * The signed-in admin user (the route middleware guarantees one).
     */
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Whether the user may see shared views of a list.
     *
     * @param  array{view: string, share: ?string}  $abilities
     */
    private function canView(User $user, array $abilities): bool
    {
        return $user->isWebmaster() || Gate::forUser($user)->allows($abilities['view']);
    }

    /**
     * Whether the user may share views of a list.
     *
     * @param  array{view: string, share: ?string}  $abilities
     */
    private function canShare(User $user, array $abilities): bool
    {
        if ($user->isWebmaster()) {
            return true;
        }

        return $abilities['share'] !== null && Gate::forUser($user)->allows($abilities['share']);
    }

    /**
     * Response item of one view. Never carries the numeric id or the owner id.
     *
     * @param  SavedView  $view      Saved view
     * @param  User       $user      Current user
     * @param  bool       $canShare  Whether the user holds the list's share ability
     * @return array{uuid: string, name: string, state: array<string, mixed>, is_shared: bool, is_mine: bool, can_manage: bool, can_delete: bool}
     */
    private function item(SavedView $view, User $user, bool $canShare): array
    {
        $isMine = (int) $view->user_id === (int) $user->getKey();

        return [
            'uuid' => $view->uuid,
            'name' => $view->name,
            'state' => (object) ($view->state ?? []),
            'is_shared' => (bool) $view->is_shared,
            'is_mine' => $isMine,
            'can_manage' => $isMine && (!$view->is_shared || $canShare),
            'can_delete' => $isMine || ($view->is_shared && $canShare),
        ];
    }

    /**
     * Plain 404 response.
     */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }

    /**
     * Plain 403 response.
     */
    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
