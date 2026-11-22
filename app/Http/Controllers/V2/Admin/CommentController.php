<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use App\Enums\StatusEnum;
use App\Models\Comment;
use App\Services\Comment\CommentBannedWords;
use Cms\Content\Models\Post;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Resources\Comment\CommentResource;
use App\Repositories\Interfaces\CommentInterface;
use Cms\Core\Http\Resources\Comment\CommentCollection;
use Cms\Core\Http\Requests\Comment\StoreCommentRequest;
use Cms\Core\Http\Requests\Comment\UpdateCommentRequest;
use Cms\Core\Http\Requests\Comment\MultiDeleteCommentRequest;

class CommentController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param CommentInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(protected CommentInterface $model)
    {
        parent::__construct();
    }

    /**
     * Set comment status
     *
     * @param int $id
     *
     * @return mixed
     *
     * @author malayvuong
     *
     * @since 6.5.5 - 2022-03-6, 0:58
     */
    public function setStatus(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id, ['id', 'target_id', 'target_type', 'status']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!in_array(request('type', 'confirmed'), ['confirmed', 'rejected'])) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->status = request('type');

        $model->save();

        $this->forgetPublishedList($model);

        return ires()->message(__('mgs.ok.' . request('type'), ['name' => __('nav.a.comments')]));
    }

    /**
     * Confirmed comment
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-09, 00:20
     */
    public function confirm(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id, ['id', 'target_id', 'target_type', 'status']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->status = StatusEnum::PUBLISHED->value;

        $model->save();

        $this->forgetPublishedList($model);

        return ires()->message(__('mgs.ok.confirmed', ['name' => __('nav.a.comments')]));
    }

    /**
     * Drop the cached list of published comments of the comment's target, under the key the site's comment blocks
     * (`CommentForm`, `CommentRating`) read, so an approval, status change, edit, delete or restore shows on the site at
     * once.
     */
    private function forgetPublishedList(Comment $model): void
    {
        cache()->forget(make_key([
            'target_id' => $model->target_id,
            'target_type' => $model->target_type,
            'reply_to' => null,
            'status' => StatusEnum::PUBLISHED->value,
        ]));
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // The admin list sends `pageSize`; `limit` is the older name. Capped like the page-size choices (10-100).
        $pageSize = min(max((int) request('pageSize', request('limit', 10)), 1), 100);

        $model = $this->model->get([
            'page' => $pageSize,
            'latest' => true,
            'when' => [
                [
                    request('type'),
                    function ($q): void {
                        $q->where(['target_type' => $this->targetType((string) request('type'))]);
                    },
                ],
            ],
            'filter' => request()->all(),
            'with' => [
                'contact:id,name,email',
                //  NOTE: UID only exists on `posts`, `products`, `items`, `places`, `categories`, `provinces` table
                //  Please be carefully to use this target
                'target:id,name',
                'comment_status',
            ],
        ]);

        return ires()->data(new CommentCollection($model));
    }

    /**
     * Comment settings: the banned-word list (one entry per string).
     */
    public function settings(CommentBannedWords $bannedWords): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(['banned_words' => $bannedWords->words()]);
    }

    /**
     * Save the comment settings. Entries are trimmed, blank and repeated ones dropped, 500 at most.
     */
    public function saveSettings(CommentBannedWords $bannedWords): mixed
    {
        if (!user()?->can('update', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $data = request()->validate([
            'banned_words' => ['present', 'array', 'max:' . CommentBannedWords::MAX_ENTRIES * 2],
            'banned_words.*' => ['nullable', 'string', 'max:255'],
        ]);

        return ires()->data(['banned_words' => $bannedWords->save($data['banned_words'])])
            ->message(__('mgs.ok.update', ['name' => __('nav.a.comments')]));
    }

    /**
     * Stored `target_type` for a list `type` (`Post`, `Item`, ...). Post comments point at the Content module's Post
     * (the public comment form stores it and `UpdateNewModelPath` moved old `App\Models\Post` rows to it); other
     * targets keep their `App\Models\` alias.
     */
    private function targetType(string $type): string
    {
        return $type === 'Post' ? Post::class : 'App\Models\\' . $type;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreCommentRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function store(StoreCommentRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.comments')]));
        }

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.comments')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     **@author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id, ['*'], ['target', 'contact', 'comment_status']);

        //  Save the last view
        $model->last_view = now();
        $model->save();

        return ires()->data(new CommentResource($model));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateCommentRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function update(int $id, UpdateCommentRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.comments')]));
        }

        $this->forgetPublishedList($model);

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.comments')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.comments')]));
        }

        $this->forgetPublishedList($model);

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.comments')]));
    }

    /**
     * Restore the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function restore(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->get([
            'where' => ['id' => $id],
            'withTrashed' => true,
            'take' => 1,
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('restore', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->restore()) {
            return ires()->error()->message(__('mgs.er.restore', ['name' => __('nav.a.comments')]));
        }

        $this->forgetPublishedList($model);

        return ires()->message(__('mgs.ok.restore', ['name' => __('nav.a.comments')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteCommentRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeleteCommentRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.comments')]));
    }
}
