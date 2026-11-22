<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Client;

use Cms\Core\Responses\AppResponse;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Cms\Core\Http\Requests\Translate\ClientTranslateTextRequest;

//use App\Clients\GoogleCloudClient;

class TranslateController extends Controller
{
    /**
     * Process to translate text from client
     *
     * @param ClientTranslateTextRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-10-22, 14:00 ICT
     */
    public function text(ClientTranslateTextRequest $request): mixed
    {
        $ggClient = new GoogleCloudClient();

        //  Count character to check quota
        $characterLength = strlen($request->input('content'));

        Log::debug('Character length', ['length' => $characterLength]);

        $translated = $ggClient->translate($request->input('content'), $request->input('from_language'), $request->input('to_language'));

        return ires()->data([
            'content' => $translated,
        ]);
    }
}
