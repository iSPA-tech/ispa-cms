<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Environment\EnvironmentSettingService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Webmaster-only admin API for environment-bound settings: list, replace and clear a value. Access is enforced by the
 * `admin.webmaster` route middleware. Only keys already declared as env-bound settings rows are accepted (422
 * otherwise); secret values are write-only and never part of any response.
 */
class EnvironmentSettingController extends AdminController
{
    /**
     * @param EnvironmentSettingService $service Environment settings service.
     */
    public function __construct(private readonly EnvironmentSettingService $service)
    {
        parent::__construct();
    }

    /**
     * List the allowed environment settings (secret values are null).
     *
     * @return mixed Admin API response.
     */
    public function index(): mixed
    {
        return ires()->data(['items' => $this->service->list()]);
    }

    /**
     * Replace the value of one environment setting.
     *
     * @param Request $request Body `{ value: string }`.
     * @param string  $key     Setting key (e.g. `email.MAIL_HOST`).
     *
     * @return mixed Admin API response with the updated item (no secret value).
     */
    public function update(Request $request, string $key): mixed
    {
        $value = $request->input('value');
        if (!is_string($value)) {
            return $this->invalid('The value is required; clear the setting instead of sending an empty value.');
        }

        try {
            $this->service->set($key, $value, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (RuntimeException $e) {
            return ires()->error()->code(500)->errorCode('ENV_WRITE_FAILED')->message($e->getMessage());
        }

        return ires()->data($this->service->item($key));
    }

    /**
     * Empty one environment setting.
     *
     * @param Request $request Signed-in Webmaster.
     * @param string  $key     Setting key.
     *
     * @return mixed Admin API response with the cleared item.
     */
    public function clear(Request $request, string $key): mixed
    {
        try {
            $this->service->clear($key, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (RuntimeException $e) {
            return ires()->error()->code(500)->errorCode('ENV_WRITE_FAILED')->message($e->getMessage());
        }

        return ires()->data($this->service->item($key));
    }

    /**
     * 422 response; the message never contains a submitted value.
     */
    private function invalid(string $message): mixed
    {
        return ires()->error()->code(422)->errorCode('VALIDATION_FAILED')->message($message);
    }
}
