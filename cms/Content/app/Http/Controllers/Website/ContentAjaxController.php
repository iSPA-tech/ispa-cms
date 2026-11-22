<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\ContentInterface;

/**
 * Ajax content block view for the public site (route `ajax.content-view`).
 *
 * Moved out of the app AjaxController on 2026-11-21 so the app ajax endpoints (contact, comment, register, language)
 * no longer need the Content module.
 *
 * @package Cms\Content\Http\Controllers\Website
 */
class ContentAjaxController extends Controller
{
    /**
     * @param ContentInterface $content Content repository.
     */
    public function __construct(protected ContentInterface $content)
    {
        parent::__construct();
    }

    /**
     * Render one content record (description, body and audio list) by uuid.
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-02-27, 22:51
     */
    public function contentView(): mixed
    {
        if (!request()->has('uuid') || !request()->validate(['uuid' => 'uuid'])) {
            abort(404);
        }

        $content = $this->content->findBy(['uuid' => request('uuid')], ['*'], ['audios:id,target_id,name,path,mime_type,category']);

        if (!$content) {
            abort(404);
        }

        $render = view('components.contents-view', ['content' => $content->content])->render();

        return ires()->data([
            'description' => $content->description,
            'content' => $render,
            'audios' => view('components.audios', ['audios' => $content->audios])->render(),
        ]);
    }
}
