<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Connects a Google Drive or OneDrive backup destination to a cloud account through OAuth.
 *
 * `start` is a Webmaster-only admin API call; `callback` is the provider's browser redirect, which carries no admin
 * token and is authenticated by the single-use state instead (see {@see OAuthConnector}).
 */
class BackupOAuthController extends AdminController
{
    /**
     * Create the controller.
     */
    public function __construct(private readonly OAuthConnector $connector)
    {
        parent::__construct();
    }

    /**
     * Begin connecting a destination: returns `{ data: { authorize_url } }` for the browser to open.
     *
     * @param Request $request Admin API request (Sanctum).
     * @param string $uuid Destination uuid.
     *
     * @return mixed Admin API response.
     */
    public function start(Request $request, string $uuid): mixed
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can connect a backup destination.')
                ->errorCode('BACKUP_OAUTH_WEBMASTER_ONLY');
        }

        $destination = BackupDestination::query()->where('uuid', $uuid)->first();
        if ($destination === null) {
            return ires()->notFound('The backup destination was not found.')
                ->errorCode('BACKUP_DESTINATION_NOT_FOUND');
        }

        if (!$this->connector->supports($destination)) {
            return ires()->error()->code(422)->errorCode('BACKUP_OAUTH_DRIVER_UNSUPPORTED')
                ->message('Only Google Drive and OneDrive destinations connect through an account.');
        }

        try {
            $url = $this->connector->start($destination, $user);
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage() === 'BACKUP_OAUTH_CLIENT_MISSING'
                ? 'BACKUP_OAUTH_CLIENT_MISSING'
                : 'BACKUP_OAUTH_START_FAILED';

            return ires()->error()->code(422)->errorCode($code)
                ->message('The destination has no OAuth client id; set it before connecting.');
        }

        return ires()->data(['authorize_url' => $url]);
    }

    /**
     * Provider redirect target: finishes the connection and sends the browser back to the Backups page with
     * `oauth=ok` or `oauth=failed`. Provider errors and tokens are never echoed.
     *
     * @param Request $request Browser request with `state` and `code` (or `error`).
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');

        $ok = $this->connector->complete(
            is_string($state) ? $state : null,
            is_string($code) ? $code : null,
            $request->query('error') !== null,
        );

        $target = rtrim((string) config('app.url'), '/') . '/iPanel/setting/system/backups?oauth=' . ($ok ? 'ok' : 'failed');

        return redirect()->away($target)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
