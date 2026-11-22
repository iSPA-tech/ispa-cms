<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Services\Modules\Install\ModuleInstallSettingException;
use Illuminate\Http\Request;

/**
 * The Webmaster's "allow module install" switch (spec 2026-11-22; Webmaster only, enforced by the route middleware).
 *
 * `GET  modules/install-setting` answers {@see ModuleInstallPolicy::resolution()}.
 * `PUT  modules/install-setting` with `{ "enabled": bool }` switches it (audited `modules.install_enabled` /
 * `modules.install_disabled`) and answers the new resolution. Refused with 409 while the server decides
 * (MODULE_INSTALL_FORBIDDEN_BY_SERVER for `MODULE_INSTALL_ENABLED=false`, MODULE_INSTALL_MANAGED_BY_SERVER for
 * `true`) or the hub forbids (MODULE_INSTALL_FORBIDDEN_BY_HUB, switching on only). A failed preparation step answers
 * 500 with MODULE_INSTALL_RUNTIME_FOLDER_FAILED, MODULE_INSTALL_ACTIVATOR_MARKER_FAILED,
 * MODULE_INSTALL_CONFIG_CACHE_FAILED or MODULE_INSTALL_WORKER_RESTART_FAILED; the call can be repeated.
 * This is the only endpoint that writes the `modules.install_enabled` setting: the generic settings endpoints refuse
 * or skip it (SettingController::PROTECTED_KEYS).
 */
final class ModuleInstallSettingController extends AdminController
{
    /**
     * @return mixed
     */
    public function show(ModuleInstallPolicy $policy)
    {
        return ires()->data($policy->resolution());
    }

    /**
     * @return mixed
     */
    public function update(Request $request, ModuleInstallPolicy $policy)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        $server = $policy->serverValue();
        if ($server === false) {
            return ires()->error()->code(409)->errorCode(InstallPreflight::FORBIDDEN_BY_SERVER)
                ->message('The server has locked module install on this site.');
        }
        if ($server === true) {
            return ires()->error()->code(409)->errorCode('MODULE_INSTALL_MANAGED_BY_SERVER')
                ->message('Module install is switched on by the server and cannot be changed here.');
        }
        if ($enabled && $policy->hubForbids()) {
            return ires()->error()->code(409)->errorCode(InstallPreflight::FORBIDDEN_BY_HUB)
                ->message('iSPA has locked module install for this website.');
        }

        $before = $policy->settingEnabled();
        try {
            $enabled ? $policy->enable() : $policy->disable();
        } catch (ModuleInstallSettingException $e) {
            return ires()->error()->code(500)->errorCode($e->errorCode)->message($e->getMessage());
        }

        if ($before !== $enabled) {
            event(new AuditLogEvent(
                $enabled ? ModuleInstallPolicy::AUDIT_ENABLED : ModuleInstallPolicy::AUDIT_DISABLED,
                ['from' => $before, 'to' => $enabled],
                Setting::class,
                $policy->settingId(),
                'fa fa-puzzle-piece',
                'warning',
            ));
        }

        return ires()->data($policy->resolution());
    }
}
