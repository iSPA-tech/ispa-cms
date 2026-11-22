<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Models\Translate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;

/**
 * Serve the admin menu of active modules that declare `admin.navigation` in their manifest.
 *
 * @see ResourceRegistry
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 */
class RegistryController extends Controller
{
    /** Navigation entry keys that may be exposed to the client. */
    private const ENTRY_KEYS = ['name', 'text', 'icon', 'rule', 'resource'];

    /**
     * Return the registry of enabled modules with the navigation the current user may see.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return JsonResponse  Registry payload with schema_version and modules
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $registry = app(ResourceRegistry::class);
        $modules = [];
        $labels = [];

        foreach (Module::all() as $module) {
            if (!$module->isEnabled()) {
                continue;
            }

            $admin = $module->get('admin');
            $admin = is_array($admin) ? $admin : [];
            $declared = $admin['navigation'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            $navigation = $this->visibleNavigation($declared, $user, $registry, $module->getName());
            if ($navigation === []) {
                continue;
            }

            $alias = (string) $module->get('alias');
            $version = $module->get('version');
            $summary = is_array($admin['summary'] ?? null) ? $admin['summary'] : [];

            $modules[] = [
                'name' => $module->getName(),
                'key' => strtolower($alias !== '' ? $alias : $module->getName()),
                'label' => $this->fallbackLabel($module),
                'version' => is_string($version) && $version !== '' ? $version : null,
                'navigation' => $navigation,
                'summary' => [
                    'tasks' => $this->stringList($summary['tasks'] ?? []),
                    'tiles' => $this->stringList($summary['tiles'] ?? []),
                ],
            ];
            $labels[] = 'nav.a.' . Str::kebab($module->getName());
        }

        // One query for every module: prefer the admin translation key of the module's navigation root.
        $present = $labels === [] ? [] : array_flip(
            Translate::query()->where('for_adm', true)->whereIn('name', $labels)->pluck('name')->all()
        );
        foreach ($modules as $i => $row) {
            if (isset($present[$labels[$i]])) {
                $modules[$i]['label'] = $labels[$i];
            }
        }

        return response()->json(['schema_version' => 1, 'modules' => $modules]);
    }

    /**
     * Label used when the module has no admin translation key: manifest display name, else the module name.
     *
     * @param  object  $module  Module instance
     * @return string  Display name or module name
     */
    private function fallbackLabel(object $module): string
    {
        $display = $module->get('displayName');

        return is_string($display) && trim($display) !== '' ? trim($display) : $module->getName();
    }

    /**
     * Filter declared entries by the user's permission rule and the resource registry.
     *
     * @param  array<int, mixed>  $declared  Raw manifest entries
     * @param  mixed              $user      Current user
     * @param  ResourceRegistry   $registry  Resource registry
     * @param  string             $module    Module name, used for logging
     * @return array<int, array<string, mixed>>  Whitelisted entries
     */
    private function visibleNavigation(array $declared, mixed $user, ResourceRegistry $registry, string $module): array
    {
        $result = [];

        foreach ($declared as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rule = $entry['rule'] ?? null;
            if (is_string($rule) && $rule !== '' && (!$user || !Gate::forUser($user)->allows($rule))) {
                continue;
            }

            $resource = $entry['resource'] ?? null;
            if (is_string($resource) && $resource !== '' && !$registry->has($resource)) {
                Log::warning('Admin registry dropped a navigation entry with an unknown resource.', [
                    'module' => $module,
                    'entry' => $entry['name'] ?? null,
                    'resource' => $resource,
                ]);
                continue;
            }

            $result[] = array_intersect_key($entry, array_flip(self::ENTRY_KEYS));
        }

        return $result;
    }

    /**
     * Reduce a manifest value to a list of strings.
     *
     * @param  mixed  $value  Raw manifest value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
