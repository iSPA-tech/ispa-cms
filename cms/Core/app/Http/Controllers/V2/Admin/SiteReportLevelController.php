<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Console\Commands\PlatformReportCommand;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Platform\SiteStatusReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Site report level for the Webmaster (; Webmaster only, enforced by the route middleware).
 *
 * `show` tells the level in use, where it comes from and the default; `update` saves the Webmaster's choice (audited,
 * refused while a `.env` level decides); `preview` returns the exact JSON the next report would send at the level in
 * use or at a chosen level, without sending or saving anything. No answer carries a secret or the hub address.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
final class SiteReportLevelController extends AdminController
{
    /**
     * The level in use, its source, the default, the saved choice and whether the site is connected.
     *
     * @return mixed
     */
    public function show(SiteStatusReport $report)
    {
        return ires()->data($this->state($report));
    }

    /**
     * Save the Webmaster's level and audit the change (from, to).
     *
     * @return mixed
     */
    public function update(Request $request, SiteStatusReport $report)
    {
        $data = $request->validate(['level' => ['required', 'string', Rule::in(SiteStatusReport::LEVELS)]]);

        $before = $report->resolution();
        if ($before['env_locked']) {
            return ires()->error()->code(409)->errorCode('REPORT_LEVEL_MANAGED_BY_ENV')
                ->message('The report level is set on the server and cannot be changed here.');
        }

        $report->storeLevel($data['level']);
        event(new AuditLogEvent(
            'platform.report_level.updated',
            ['from' => $before['level'], 'to' => $data['level']],
            Setting::class,
            (int) Setting::query()->where('setting_key', SiteStatusReport::SETTING_KEY)->value('id'),
            'fa fa-signal',
            'warning',
        ));

        return ires()->data($this->state($report));
    }

    /**
     * The exact pretty-printed JSON the next report would send (null for `off`), as `cms:platform-report --dry-run`
     * prints it.
     *
     * @return mixed
     */
    public function preview(Request $request, SiteStatusReport $report)
    {
        $data = $request->validate(['level' => ['sometimes', 'nullable', 'string', Rule::in(SiteStatusReport::LEVELS)]]);
        $level = $data['level'] ?? $report->level();
        $payload = $report->payload($level);

        return ires()->data([
            'level' => $level,
            'json' => $payload === null ? null : PlatformReportCommand::pretty($payload),
        ]);
    }

    /**
     * Response body shared by show and update.
     *
     * @return array<string, mixed>
     */
    private function state(SiteStatusReport $report): array
    {
        return $report->resolution() + ['levels' => SiteStatusReport::LEVELS];
    }
}
