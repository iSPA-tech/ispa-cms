<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Admin;

use Cms\Core\Responses\AppResponse;

use App\Http\Controllers\Controller;
use Cms\Core\Interfaces\LanguageInterface;
use V3\Resources\Admin\Language\LanguageCollection;

class LanguageController extends Controller
{
    /**
     * LanguageController constructor.
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-14, 10:27 ICT
     */
    public function __construct(
        protected LanguageInterface $language,
    )
    {
        parent::__construct();
    }

    /**
     * Get language list
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-14, 10:30 ICT
     */
    public function index(): mixed
    {
        $data = $this->language->get([
            'latest' => true,
        ]);

        return ires()->data(new LanguageCollection($data));
    }
}
