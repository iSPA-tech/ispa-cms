<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;
use App\Enums\StatusEnum;
use Cms\Core\Http\Requests\Field\PreviewFieldRequest;
use Cms\Core\Models\Field;
use Cms\Core\Models\FieldItem;
use Cms\Core\Services\Forms\FormOptionResolver;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Cms\Core\Interfaces\FieldInterface;
use Cms\Core\Http\Requests\Field\StoreFieldRequest;
use Cms\Core\Http\Requests\Field\UpdateFieldRequest;
use Cms\Core\Interfaces\FieldItemInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Field\MultiDeleteFieldRequest;

/**
 * CRUD operations for custom form fields and their items.
 *
 * Manages dynamic field definitions and their associated option items.
 * Supports searching, creating, updating, and deleting fields in a
 * transactional manner. Extends AdminController.
 *
 * @see FieldInterface
 * @see FieldItemInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class FieldController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param FieldInterface $model
     * @param FieldItemInterface $item
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(protected FieldInterface $model, protected FieldItemInterface $item)
    {
        parent::__construct();
    }

    /**
     * Search query
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 12/6/21 13:03
     */
    public function search(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'when' => [
                [
                    request('query'),
                    function ($q): void {
                        $q->where('name', 'like', '%' . request('query') . '%')->orWhere('form_id', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
            'take' => 30,
            'latest' => true,
        ]);

        return ires()->data($model);
    }

    /**
     * Display a listing of the resource.
     *
     * @return $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'order' => ['id' => 'desc'],
//            'page' => request('pageSize', 30),
            'with' => ['items:id,field_id,name,custom'],
            'filter' => request()->input(),
        ]);

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreFieldRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreFieldRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->form_id = $request->input('form_id') ?: 'form_' . Str::ulid();
        app(FormOptionResolver::class)->validateSettings($this->withDraftItems($model, $request->input('items')));

        DB::beginTransaction();

        try {
            $model->save();

            foreach (request('items') as $key => $item) {
                //  Save new item
                $save = $this->item->reset()->model();
                $save->fill($item);

                $save->priority = $key;
                $save->field_id = $model->id;
                $save->save();
            }
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.fields')]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.error.404'));
        }

        $model = $this->model->find($id, ['*'], ['items']);

        if (!$model) {
            return ires()->error()->message(__('mgs.error.404'));
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
     * @param UpdateFieldRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $id, UpdateFieldRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $formId = $model->form_id;
        $model = $this->fill($model);
        $model->form_id = $formId;
        app(FormOptionResolver::class)->validateSettings($this->withDraftItems(clone $model, $request->input('items')));

        $savedItems = $model->items()->get()->keyBy('id');
        foreach ($request->input('items', []) as $key => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId > 0 && (!$savedItems->has($itemId)
                || $savedItems->get($itemId)->field_name !== $item['field_name'])) {
                throw ValidationException::withMessages([
                    "items.$key.id" => 'Mục đã chọn không thuộc biểu mẫu này hoặc đã thay đổi. Vui lòng tải lại biểu mẫu.',
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $model->save();

            foreach (request('items') as $key => $item) {
                if ($item['id'] > 0) {
                    $fieldItem = $this->item->find($item['id']);
                } else {
                    $fieldItem = $this->item->reset()->model();
                }
                $fieldItem->fill($item);

                $fieldItem->priority = $key;
                $fieldItem->field_id = $model->id;

                $fieldItem->save();
            }
            //  Remove cache
            cache()->forget(make_key(['type' => 'form', 'form_id' => $model->form_id]));
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.fields')]));
    }

    public function preview(PreviewFieldRequest $request): \Illuminate\Http\Response
    {
        $data = $request->validated();
        $id = (int) ($data['id'] ?? 0);
        $saved = $id > 0 ? $this->model->find($id) : null;

        if ($id > 0 && !$saved) {
            abort(404);
        }

        abort_unless(user()?->can($saved ? 'update' : 'create', $saved ?: $this->model->model()), 403);

        $fields = new Field();
        $fields->id = 1;
        $fields->form_id = $saved?->form_id ?: 'preview';
        $fields->name = $data['name'] ?? '';
        $fields->status = StatusEnum::PUBLISHED->value;
        $fields->custom = $data['custom'] ?? [];
        $fields->setRelation('items', collect($data['items'])->map(function (array $item): FieldItem {
            $fieldItem = new FieldItem();
            $fieldItem->fill($item);
            $fieldItem->status = (bool) ($item['status'] ?? true);

            return $fieldItem;
        }));
        if ($saved) {
            // Keep system-owned project metadata while previewing only draft layout changes.
            $fields->custom = array_replace((array) $saved->custom, array_intersect_key((array) ($data['custom'] ?? []), ['layout' => true]));
        }
        app(FormOptionResolver::class)->validateSettings($fields);

        return response()->view('components.form-preview', [
            'fields' => $fields,
            'form' => 'preview',
            'actionUrl' => '',
            'submissionToken' => null,
            'previewOnly' => true,
        ])->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        Db::beginTransaction();
        try {
            $model->delete();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.fields')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteFieldRequest $request
     *
     * @return $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeleteFieldRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.fields')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     *
     **@author malayvuong
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    private function withDraftItems(Field $form, array $items): Field
    {
        return $form->setRelation('items', collect($items)->map(fn (array $item) => new FieldItem($item)));
    }
}
