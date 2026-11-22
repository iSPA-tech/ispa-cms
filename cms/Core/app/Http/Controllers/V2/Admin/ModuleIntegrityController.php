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
use Cms\Core\Services\Modules\Install\ModuleIntegrity;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Integrity report of installed modules for the Webmaster (; Webmaster only, enforced by the route middleware).
 *
 * `index` returns the last stored result (from the daily command or the last "check now"); `check` runs the comparison
 * now and stores it. Both are information only: nothing here changes a module or blocks an action.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
final class ModuleIntegrityController extends AdminController
{
    /**
     * The last stored result; `checked_at` is null and `modules` empty when no check ran lately.
     *
     * @return mixed
     */
    public function index()
    {
        return ires()->data(self::payload(ModuleIntegrity::stored()));
    }

    /**
     * Compare the files now, store the result and return it.
     *
     * @return mixed
     */
    public function check(ModuleIntegrity $integrity)
    {
        $known = [];
        try {
            foreach (ModuleFacade::all() as $module) {
                $known[] = $module->getName();
            }
        } catch (Throwable) {
            // Records alone still give a result.
        }
        $results = $integrity->checkAll($integrity->names($known));
        ModuleIntegrity::store($results);

        return ires()->data(self::payload(ModuleIntegrity::stored() ?? ['checked_at' => null, 'modules' => $results]));
    }

    /**
     * Response body: the check time and, per module, state, version, changed count and the listed files.
     *
     * @param array{checked_at: ?string, modules: array<string, array<string, mixed>>}|null $stored The stored result.
     * @return array<string, mixed>
     */
    private static function payload(?array $stored): array
    {
        return ['checked_at' => $stored['checked_at'] ?? null, 'modules' => (object) ($stored['modules'] ?? [])];
    }
}
