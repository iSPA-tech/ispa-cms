<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Client;

use App\Http\Controllers\Controller;
use Cms\Content\Models\BookMark;
use Cms\Core\Responses\AppResponse;
use Illuminate\Http\Request;

/**
 * Logged-in reader bookmarks ("Lưu") over the existing morph `bookmarks`
 * table. Targets arrive as the tracking alias vocabulary (post, product,
 * vehicle-model, vehicle-engine, …) plus a uuid — public payloads never
 * expose numeric ids — and resolve to the morph class + integer key here.
 */
class BookmarkController extends Controller
{
    /** Aliases the public reader may bookmark. */
    private const ALLOWED_ALIASES = [
        'post',
        'product',
        'product-variant',
        'vehicle-model',
        'vehicle-engine',
        'place',
    ];

    /** The signed-in reader's bookmarks, newest first. */
    public function index(Request $request): AppResponse
    {
        $rows = BookMark::query()
            ->where('user_id', user()->id)
            ->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->query('pageSize', 20))));

        $aliases = (array) config('tracking.target_aliases', []);
        $items = collect($rows->items())->map(static fn (BookMark $row): array => [
            'id' => $row->id,
            'target' => $aliases[$row->target_type] ?? $row->target_type,
            'target_id' => $row->target_id,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ]);

        return ires()->data([
            'items' => $items,
            'total' => $rows->total(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** Is this target bookmarked by the signed-in reader? */
    public function status(Request $request): AppResponse
    {
        $target = $this->resolveTarget($request);
        if ($target === null) {
            return ires()->data(['bookmarked' => false, 'known' => false]);
        }

        return ires()->data([
            'bookmarked' => BookMark::query()
                ->where('user_id', user()->id)
                ->where('target_type', $target['type'])
                ->where('target_id', $target['id'])
                ->exists(),
            'known' => true,
        ]);
    }

    /** Save / unsave in one call; responds with the new state. */
    public function toggle(Request $request): AppResponse
    {
        $target = $this->resolveTarget($request);
        abort_if($target === null, 422, 'Unknown bookmark target');

        $existing = BookMark::query()
            ->where('user_id', user()->id)
            ->where('target_type', $target['type'])
            ->where('target_id', $target['id'])
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return ires()->data(['bookmarked' => false]);
        }

        BookMark::query()->create([
            'user_id' => user()->id,
            'target_type' => $target['type'],
            'target_id' => $target['id'],
        ]);

        return ires()->data(['bookmarked' => true]);
    }

    /**
     * Alias + uuid (or id) → morph class + integer key. Null when the alias
     * is outside the reader vocabulary or the row doesn't exist — status
     * treats that as "not bookmarked", toggle rejects it.
     *
     * @return array{type: string, id: int}|null
     */
    private function resolveTarget(Request $request): ?array
    {
        $alias = (string) $request->input('target', $request->query('target', ''));
        if (! in_array($alias, self::ALLOWED_ALIASES, true)) {
            return null;
        }

        $class = array_search($alias, (array) config('tracking.target_aliases', []), true);
        if ($class === false || ! class_exists($class)) {
            return null;
        }

        $uuid = trim((string) $request->input('uuid', $request->query('uuid', '')));
        $id = (int) $request->input('id', $request->query('id', 0));

        if ($uuid !== '') {
            $resolved = $class::query()->where('uuid', $uuid)->value('id');

            return $resolved ? ['type' => $class, 'id' => (int) $resolved] : null;
        }
        if ($id > 0 && $class::query()->whereKey($id)->exists()) {
            return ['type' => $class, 'id' => $id];
        }

        return null;
    }
}
