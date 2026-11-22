<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Public;

use Cms\Core\Responses\AppResponse;

use JsonException;
use App\Http\Controllers\Controller;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Interfaces\MetaClassInterface;
use Cms\Core\Http\Resources\Website\PublicMetadataCollection;

class MetaController extends Controller
{
    /**
     * MetaController constructor.
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-11, 10:33 ICT
     */
    public function __construct(
        protected MetaClassInterface $metaClass,
    )
    {
        parent::__construct();
    }

    /**
     * Get User meta data
     *
     * @throws JsonException
     * @author malayvuong
     * @since 6.62.6 - 2023-03-11, 10:33 ICT
     */
    public function user(): mixed
    {
        $data = $this->metaClass->get([
            'where' => [
                'type' => 'class',
                'target' => 'User',
            ],
            'with' => [
                'group',
                'options',
            ],
        ]);

        $parseData = new PublicMetadataCollection($data);

        //  Set data to Redis
        RedisHelper::set([
            'meta', 'user', lang('path', 'vi'),
        ], $parseData->toArray(request()));

        return ires()->message('ok');
    }
}
