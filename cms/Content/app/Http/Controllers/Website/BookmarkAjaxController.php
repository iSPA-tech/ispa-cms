<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\Website;

use Exception;
use App\Constants\Constant;
use Cms\Content\Models\Post;
use Cms\Core\Helpers\CacheHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\BookmarkInterface;
use Cms\Content\Http\Requests\Ajax\SaveBookmarkRequest;

/**
 * Ajax bookmark toggle for the public site (route `ajax.save-bookmark`).
 *
 * Moved out of the app AjaxController so the bookmark feature ships with the Content module.
 *
 * @package Cms\Content\Http\Controllers\Website
 */
class BookmarkAjaxController extends Controller
{
    /**
     * @param BookmarkInterface $bookmark Bookmark repository.
     */
    public function __construct(protected BookmarkInterface $bookmark)
    {
        parent::__construct();
    }

    /**
     * Save bookmark
     *
     * @param SaveBookmarkRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-20, 20:11 ICT
     */
    public function saveBookmark(SaveBookmarkRequest $request): mixed
    {
        if (!auth()->check()) {
            return ires()->error()->code(401)->message(__('mgs.auth.error'));
        }

        $data = $request->validated();
        $targetType = $this->resolveContentTargetType($data['type']);

        DB::beginTransaction();
        try {
            $checkExists = $this->bookmark->findBy([
                'target_type' => $targetType,
                'target_id' => $data['id'],
                'user_id' => user('id'),
            ]);
            if ($checkExists) {
                $checkExists->delete();
            } else {
                $model = $this->bookmark->model();
                $model->target_type = $targetType;
                $model->target_id = $data['id'];
                $model->user_id = user('id');

                $model->save();
            }
            //  Remove cache
            CacheHelper::getInstance()->forgetByTag(Constant::CACHE_BY_BOOKMARKS, 'user_id_' . user('id', 0));
        } catch (Exception $e) {
            DB::rollBack();
            Log::debug($e->getMessage(), $e->getTrace());

            return ires()->error()->message(__('mgs.er.save', ['name' => __('Bookmark')]));
        }
        DB::commit();

        //  The legacy action returned an undefined variable here (ErrorException); a plain success response replaces it.
        return ires();
    }

    /**
     * Resolve a posted target type alias to a model class (same rules as the Ajax comment action).
     *
     * @param string $type Posted type alias or class name.
     *
     * @return string
     */
    protected function resolveContentTargetType(string $type): string
    {
        $type = ltrim(trim($type), '\\');

        $contentPostTypes = [
            'post',
            strtolower(Post::class),
            'app\\models\\post',
            'app\\models\\articles\\post',
        ];

        if (in_array(strtolower($type), $contentPostTypes, true)) {
            return Post::class;
        }

        if (class_exists($type)) {
            return $type;
        }

        return 'App\\Models\\' . str($type)->ucfirst();
    }
}
