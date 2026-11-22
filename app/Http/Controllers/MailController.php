<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers;

use App\Support\LocaleManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;

class MailController extends Controller
{
    public EmailLogInterface $model;

    public function __construct(EmailLogInterface $model)
    {
        parent::__construct();

        $this->model = $model;
    }

    /**
     * Show email detail
     *
     * @author malayvuong
     * @since 1.0.0 - 12/6/21 15:53
     */
    public function show(string $uid): Factory|View|Application
    {
        if (!$uid) {
            abort(404, 'Mail not found.');
        }

        $model = $this->model->findBy(['uuid' => $uid]);

        if (!$model) {
            abort(404, 'Mail not found.');
        }

        $model = collect($model)->toArray();
        $model['brand'] = config('app.name', env('APP_NAME'));

        $model = array_merge($model, $model['content']);

        if ($locale = LocaleManager::sanitize($model['locale'] ?? null)) {
            App::setLocale($locale);
        }

        return view(($model['blade'] ?? 'mail.verify-email'), $model);
    }
}
