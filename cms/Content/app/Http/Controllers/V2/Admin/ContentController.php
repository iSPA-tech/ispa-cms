<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use ErrorException;
use App\Enums\ContentStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Content\Jobs\CallApiToTextToSpeechJob;
use App\Events\TranslateContentRequest;
use Cms\Core\Http\Controllers\AdminController;
use App\Events\TextToSpeechContentRequest;
use Cms\Content\Interfaces\ContentInterface;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Content\Http\Requests\Content\TranslateRequest;
use Cms\Content\Http\Resources\Content\ContentResource;
use Cms\Content\Http\Requests\Content\GetContentsRequest;
use Cms\Content\Http\Resources\Content\ContentCollection;
use Cms\Content\Http\Requests\Content\StoreContentRequest;
use Cms\Content\Http\Requests\Content\UpdateContentRequest;
use Cms\Content\Http\Requests\Content\MultiDeleteContentRequest;
use Cms\Content\Http\Requests\Content\PutQuickUpdateContentRequest;

/**
 * Admin V2 controller for managing Content entities (multilingual content records
 * attached to Place, Item, and other target models via polymorphic relationships).
 *
 * Exposes the full CRUD surface plus specialized operations:
 *   - index        GET     /v2/admin/contents              List contents (language_id=1)
 *   - store        POST    /v2/admin/contents              Create a new content record
 *   - show         GET     /v2/admin/contents/{uuid}       Fetch a single content record
 *   - edit         GET     /v2/admin/contents/{uuid}/edit  Fetch content for editing
 *   - update       PUT     /v2/admin/contents/{uuid}       Update a content record
 *   - destroy      DELETE  /v2/admin/contents/{uuid}       Soft-delete a content record (numeric id still accepted)
 *   - massDestroy  DELETE  /v2/admin/contents/mass         Bulk soft-delete
 *   - quickUpdate  PUT     /v2/admin/contents/{uuid}/quick Partial update (status / is_confirmed)
 *   - confirmation PUT     /v2/admin/contents/{uuid}/confirm  Confirm and trigger auto-translation
 *   - convertAudio PUT     /v2/admin/contents/{uuid}/audio    Queue text-to-speech conversion
 *   - audio        POST    /v2/admin/contents/{id}/audio      Fire TTS event for a content by id
 *   - translate    POST    /v2/admin/contents/{id}/translate/{lang}  Queue translation to a specific language
 *   - translateManual POST /v2/admin/contents/{uuid}/translate-manual  Manually trigger translation workflow
 *
 * @see \Cms\Content\Interfaces\ContentInterface
 * @see \Cms\Core\Http\Controllers\AdminController
 * @package Cms\Content\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param ContentInterface $model
     * @param LanguageInterface $language
     *
     * @author malayvuong
     * @since 1.0.0
     * @version 6.62.6 - 2023-01-30, 15:04 ICT
     */
    public function __construct(
        /** @var ContentInterface $model */
        protected ContentInterface  $model,
        /** @var LanguageInterface $language */
        protected LanguageInterface $language,
    )
    {
        parent::__construct();
    }

    /**
     * Process to translation content
     *
     * @param  string $uuid
     * @param  TranslateRequest $request
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-08-22, 10:25 ICT
     */
    public function translateManual(string $uuid, TranslateRequest $request): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['id', 'target_id', 'target_type', 'language_id'], ['target:id,uid']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        //  Check permission
        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $isForce = $request->boolean('force', false);
        //  If current language is vietnamese (language_id = 1), then request translate to other languages
        if ($model->language_id === 1) {
            //  If request force is true, then override all languages
            if ($isForce) {
                $contents = $this->model->reset()->get([
                    'select' => [
                        'id', 'status', 'custom',
                    ],
                    'where' => [
                        ['language_id', '!=', 1],
                        'target_type' => $model->target_type,
                        'target_id' => $model->target_id,
                    ],
                ]);
            } else {
                $contents = $this->model->reset()->get([
                    'select' => [
                        'id', 'status', 'custom',
                    ],
                    'where' => [
                        ['language_id', '!=', 1],
                        'target_type' => $model->target_type,
                        'target_id' => $model->target_id,
                        'status' => ContentStatusEnum::CONTENT_DRAFT->value,
                    ],
                ]);
            }
            //  Fire event to translate content
            foreach ($contents as $content) {
                //  3 request to translate (name, description, content)
                event(new TranslateContentRequest($model->id, $content->id, ['name', 'description'], $model->target->uid));
                event(new TranslateContentRequest($model->id, $content->id, ['content'], $model->target->uid));
            }
        } else if (($isForce && $model->status === ContentStatusEnum::CONTENT_PUBLISHED->value) || $model->status === ContentStatusEnum::CONTENT_DRAFT->value) {
            $vietnamese = $this->model->reset()->findBy([
                'language_id' => 1,
                'target_id' => $model->target_id,
                'target_type' => $model->target_type,
            ], ['id', 'status', 'custom']);
            if (!$vietnamese) {
                return ires()->error()->message(__('mgs.model.404'));
            }

            event(new TranslateContentRequest($vietnamese->id, $model->id, ['name', 'description'], $model->target->uid));
            event(new TranslateContentRequest($vietnamese->id, $model->id, ['content'], $model->target->uid));
        }

        return ires()->message(__('mgs.ok.translate'));
    }

    /**
     * Confirm content
     *
     * @param string $uuid
     * @param $res
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-06-21, 20:58 ICT
     */
    public function confirmation(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], [
            'target:id,uid',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        //  Check user permission
        if (!user()?->can('content.confirm')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->is_confirmed = true;
        if ($model->language_id !== 1) {
            $model->status = ContentStatusEnum::CONTENT_MODERATED->value;
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return ires()->message(__('mgs.ok.confirmed', ['name' => $model->name]));
    }

    /**
     * Confirm content
     *
     * @param string $uuid
     * @param $res
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-06-21, 20:58 ICT
     */
    public function convertAudio(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], [
            'target:id,uid',
            'audios',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        //  Check user permission
        if (!user()?->can('content.confirm')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }
        //  Request event to convert audio
        if ($model->audios->count() < 1 || (bool)request('force', false) === true) {
            event(new TextToSpeechContentRequest($model->id, $model->target->uid ?? null));
        }

        return ires()->message(__('mgs.ok.convert-request', ['name' => $model->name]));
    }

    /**
     * Quick Update content
     *      -- col: status, is_confirmed (column name will be updated)
     *      -- val: value of column (status, is_confirmed)
     *
     * @param string $uuid
     * @param PutQuickUpdateContentRequest $request
     * @param $res
     *
     * @return mixed
     * @author vuongnm
     * @since 6.62.6 - 2023-01-31, 10:23 ICT
     */
    public function quickUpdate(string $uuid, PutQuickUpdateContentRequest $request): mixed
    {
        $col = $request->input('col');
        $val = $request->input('val');
        if (empty($uuid) || empty($col)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!in_array($col, ['status', 'is_confirmed'])) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        //  Check permission by column
        if (
            ($col === 'status' && !user()?->can('update', $model)) ||
            ($col === 'is_confirmed' && !user()?->can('confirm', $model))
        ) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($col === 'status') {
            $model->status = $val;
        } else {
            $model->is_confirmed = $val;
            if ($val === true) {
                //  Call event to translate content to other languages
//                $languages = $this->language->get([
//                    'select' => ['id', 'path'],
//                    'where' => [
//                        'status' => Constant::STATUS_PUBLISHED,
//                    ]
//                ]);
//                foreach ($languages as $language) {
//                    if ($language->path !== 'vi') {
//                        $check = $this->model->reset()->model()->firstOrCreate([
//                            'language_id' => $language->id,
//                            'target_id' => $model->id,
//                            'target_type' => get_class($model),
//                        ], [
//                            'uuid' => str()->uuid(),
//                            'content' => [['type' => 'text', 'value' => null]],
//                            'status' => 'draft',
//                            'is_confirmed' => false,
//                            'user_id' => $model->user_id,
//                        ]);
//
//                        event(new TranslateContentRequest($model->id, $check->id, 'name'));
//                        event(new TranslateContentRequest($model->id, $check->id, 'description'));
//                        event(new TranslateContentRequest($model->id, $check->id, 'content'));
//                    }
//                }
                //  Call event to convert content to audio
//                event(new TextToSpeechContentRequest($model->id, $model->user_id));
                CallApiToTextToSpeechJob::dispatch($model->id, $model->user_id);
            }
        }
        $model->save();

        return ires()->message(__('mgs.ok.update'));
    }

    /**
     * Process to text-to-speech content
     *
     * @param int $id
     * @param $res
     *
     * @return mixed
     * @author malayvuong
     * @since 6.19.6 - 2022-05-29, 01:44 ICT
     */
    public function audio(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        event(new TextToSpeechContentRequest($model->id, request('uid')));

        return ires()->data();
    }

    /**
     * Process to translate content
     *
     * @param int $id
     * @param string $language
     * @param $res
     *
     * @return mixed
     * @author malayvuong
     * @since 6.19.6 - 2022-05-29, 01:44 ICT
     */
    public function translate(int $id, string $language): mixed
    {
        if ($id < 1 || !in_array($language, ['en', 'vi', 'ko'])) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);
        $lang = $this->language->findBy(['path' => $language]);

        if (!$model || !$lang) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        $check = $this->model->reset()->findBy([
            'language_id' => $lang->id,
            'target_id' => $model->target_id,
            'target_type' => $model->target_type,
        ]);

        if (!$check) {
            $check = $this->model->reset()->create([
                'language_id' => $lang->id,
                'target_id' => $model->target_id,
                'target_type' => $model->target_type,
                'uuid' => $this->model->checkUnique('uuid', str()->uuid()),
                'user_id' => $model->user_id,
            ]);
            $check->fresh();
        }

        //  3 request to translate (name, description, content)
        event(new TranslateContentRequest($model->id, $check->id, ['name', 'description'], request('uid')));
        event(new TranslateContentRequest($model->id, $check->id, ['content'], request('uid')));

        return ires()->data();
    }

    /**
     * Display a listing of the resource.
     *
     * @param GetContentsRequest $request
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function index(GetContentsRequest $request): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'select' => [
                'id', 'uuid', 'name', 'description', 'language_id', 'target_id', 'target_type',
                'user_id', 'created_at', 'updated_at', 'status', 'is_confirmed',
            ],
            'latest' => true,
            'filter' => $request->all(),
            'page' => $request->input('pageSize', 10),
            'with' => [
                'target' => function ($query) {
                    $query->select('id', 'name', 'uid');
                },
                'language:id,name,flag,path',
                'contentStatus:name,name_alias,enum_key',
            ],
            'where' => [
                'language_id' => 1,
            ],
            'when' => [
                [
                    !empty($request->input('target')) &&
                    in_array($request->input('target'), ['Place', 'Item']),
                    function ($query) use ($request) {
                        $target = $request->input('target');
                        $targetIds = [];
                        //  Get id of user by target & permission
                        $model = $this->{str($target)->lower()};
                        if (strpos(get_class($model), $target, true) > -1) {
                            if (!user()?->can('create', $model->model())) {
                                $targetIds = $model->get([
                                    'select' => ['id'],
                                    'where' => [
                                        'user_id' => user('id'),
                                    ],
                                ])->pluck('id')->toArray();
                            } else if (!user()?->can('confirm', $model->model())) {
                                //  Get all the parent id of user
                                $userIds = user()->parents->pluck('id')->toArray();

                                $targetIds = $model->get([
                                    'select' => ['id'],
                                    'whereIn' => [
                                        'user_id' => $userIds,
                                    ],
                                ])->pluck('id')->toArray();
                            }
                        }

                        $query->where(function ($q) use ($targetIds, $target): void {
                            $q
                                ->where('target_type', 'like', '%\\' . $target)
                                ->when(
                                    !empty($targetIds),
                                    fn($q) => $q->whereIn('target_id', $targetIds)
                                );
                        });
                    },
                ],
            ],
        ]);

        return ires()->data(new ContentCollection($model));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreContentRequest $request
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreContentRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model->save();
        } catch (ErrorException $e) {
            Log::error($e->getMessage(), $request->all());
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.contents')]));
        }
        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.contents')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     * @author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if (empty($model->uuid)) {
            $model->uuid = str()->uuid();
        }

        if (empty($model->user_id)) {
            $model->user_id = user('id');
        }

        return $model;
    }

    /**
     * Display the specified resource.
     *
     * @param string $uuid
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function show(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], [
            'audios',
            'contentStatus:name,name_alias,enum_key',
            'language:id,name,flag,path',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return ires()->data(new ContentResource($model));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param string $uuid
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function edit(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], [
            'audios',
            'target:id,name',
            'language:id,name,path,flag',
            'contentStatus:name,name_alias,enum_key',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new ContentResource($model));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param string $uuid
     * @param UpdateContentRequest $request
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function update(string $uuid, UpdateContentRequest $request): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], ['target:id,uid']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        DB::beginTransaction();
        try {
            $model->save();
        } catch (ErrorException $e) {
            Log::error($e->getMessage(), $request->all());
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.contents')]));
        }
        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.contents')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * The route parameter is a uuid (UUID-first admin rule). A purely numeric value is still accepted as the
     * numeric id so callers written before the rename keep working.
     *
     * @param string $uuid
     * @param $res
     *
     * @return $res
     * @author malayvuong
     *
     */
    public function destroy(string $uuid): mixed
    {
        if ($uuid === '' || $uuid === '0') {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = ctype_digit($uuid)
            ? $this->model->find((int) $uuid)
            : $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model->delete();
        } catch (ErrorException $e) {
            Log::error($e->getMessage(), ['uuid' => $uuid]);
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.contents')]));
        }
        DB::commit();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.contents')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteContentRequest $request
     * @param $res
     *
     * @return $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeleteContentRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.contents')]));
    }
}
