<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Public;

use Cms\Core\Responses\AppResponse;

use Throwable;
use App\Http\Controllers\Controller;
use Cms\Core\Http\Requests\V3\StoreContactRequest;
use App\Services\ContactSubmissionService;

class ContactController extends Controller
{
    /**
     * Contact controller constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-01, 09:14 ICT
     */
    public function __construct(
        protected ContactSubmissionService $service,
    )
    {
        parent::__construct();
    }

    /**
     * Store new contact from client
     *
     * @author malayvuong
     * @since  - 2023-03-01, 08:36 ICT
     */
    public function store(StoreContactRequest $request): mixed
    {
        try {
            $result = $this->service->submit($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ires()->error()->message(__('mgs.form.error'));
        }

        if ($result['blocked'] ?? false) {
            return ires()->message(__('mgs.form.success'));
        }

        return ires()
            ->data([
                'id' => $result['contact']->id,
                'email_id' => $result['email']->id,
            ])
            ->message(__('mgs.form.success'));
    }
}
