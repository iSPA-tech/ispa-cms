<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\User\UserTagStoreRequest;
use Cms\Core\Models\Tag;
use Cms\Core\Services\UserTagService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Admin API for user tags (UUID-first): search, create-or-return-existing, hard delete. Read needs `user.viewAny`, write needs `user.update`. */
class UserTagController extends AdminController
{
    /** @param UserTagService $tags The Core tag service. */
    public function __construct(private readonly UserTagService $tags)
    {
        parent::__construct();
    }

    /** Search tags by name (accent-insensitive through the slug): up to 50 rows with their user count. */
    public function index(Request $request): mixed
    {
        abort_unless($request->user()?->can('user.viewAny'), 403);
        $query = Tag::query()->ofType(Tag::TYPE_USER)->withCount('users');
        if (($q = trim((string) $request->query('q'))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $slug = '%' . str_replace(['%', '_'], ['\\%', '\\_'], Str::slug($q)) . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $slug));
        }

        return ires()->data(['items' => $query->orderBy('name')->limit(50)->get()->map(fn (Tag $t): array => $this->present($t))->values()]);
    }

    /** Create a tag, or return the one with the same slug (`Triết` and `Triet` are one tag). */
    public function store(UserTagStoreRequest $request): mixed
    {
        $tag = $this->tags->findOrCreateByName((string) $request->validated('name'))->loadCount('users');

        return ires()->data($this->present($tag))->message('Đã lưu tag.');
    }

    /** Hard-delete a tag by uuid; the pivot rows go with it. */
    public function destroy(Request $request, string $uuid): mixed
    {
        abort_unless($request->user()?->can('user.update'), 403);
        $tag = Tag::query()->ofType(Tag::TYPE_USER)->where('uuid', $uuid)->first();
        abort_if($tag === null, 404, 'Tag không còn tồn tại.');
        $this->tags->deleteTag($tag);

        return ires()->message('Đã xóa tag.');
    }

    /** @return array{uuid:string,name:string,users_count:int} */
    private function present(Tag $tag): array
    {
        return ['uuid' => $tag->uuid, 'name' => $tag->name, 'users_count' => (int) $tag->users_count];
    }
}
