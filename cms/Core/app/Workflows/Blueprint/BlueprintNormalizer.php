<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Blueprint;

use ReflectionClass;
use ReflectionMethod;

/**
 * Normalizes raw workflow blueprint definitions by filtering and enriching callable methods.
 *
 * Applies category-specific method filters (service, job, event, command) and enforces
 * security policies by hiding disallowed functions based on prefix and parameter type rules.
 *
 * @package Cms\Core\Workflows\Blueprint
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BlueprintNormalizer
{
    /**
     * Pass-through normalizer for future expansion.
     *
     * @param  array<int,mixed> $rawBlueprint  The raw blueprint items to normalize.
     * @param  bool             $compact        Whether to produce a compact output.
     * @return array<int,mixed> The normalized blueprint items.
     */
    public function normalize(array $rawBlueprint, bool $compact = false): array
    {
        $normalized = [];

        foreach ($rawBlueprint as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['functions']) && $item['functions'] instanceof \Illuminate\Support\Collection) {
                $item['functions'] = $item['functions']->all();
            }

            if (($item['category'] ?? null) === 'service' && isset($item['class'])) {

                $item['functions'] = $this->filterServiceMethods($item['class'], $item['functions'] ?? []);
            }

            if (($item['category'] ?? null) === 'job' && isset($item['functions'])) {
                $item['functions'] = $this->filterJobMethods($item['functions']);
            }

            if (($item['category'] ?? null) === 'event' && isset($item['functions'])) {
                $item['functions'] = $this->filterEventMethods($item['functions']);
            }

            if (($item['category'] ?? null) === 'command' && isset($item['functions'])) {
                $item['functions'] = $this->filterCommandMethods($item['functions']);
            }

            if (isset($item['functions']) && is_array($item['functions'])) {
                $item['functions'] = $this->applyPolicy($item['functions']);
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Filter service methods to include only class-declared public methods (no magic/constructor).
     *
     * @param array<int,array<string,mixed>> $functions
     * @return array<int,array<string,mixed>>
     */
    protected function filterServiceMethods(string $class, array $functions): array
    {
        if (!class_exists($class)) {
            return $functions;
        }

        $ref = new ReflectionClass($class);
        $allowedNames = collect($ref->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(function (ReflectionMethod $method) use ($class) {
                if ($method->isConstructor() || $method->isDestructor()) {
                    return false;
                }
                if (str_starts_with($method->getName(), '__')) {
                    return false;
                }
                return $method->getDeclaringClass()->getName() === $class;
            })
            ->map(fn(ReflectionMethod $m) => $m->getName())
            ->toArray();

        return collect($functions)->filter(function ($fn) use ($allowedNames) {
            return isset($fn['name']) && in_array($fn['name'], $allowedNames, true);
        })->map(function ($fn) {
            $fn['ui'] = $fn['ui'] ?? ['hidden' => false];
            return $fn;
        })->values()->all();
    }

    /**
     * Filter job methods based on allowed list.
     *
     * @param array<int,array<string,mixed>> $functions
     * @return array<int,array<string,mixed>>
     */
    protected function filterJobMethods(array $functions): array
    {
        $allowed = (array) config('core.workflow_blueprint.allowed_job_methods', []);

        return collect($functions)
            ->filter(fn($fn) => isset($fn['name']) && in_array($fn['name'], $allowed, true))
            ->map(function ($fn) {
                $fn['ui'] = $fn['ui'] ?? ['hidden' => false];
                return $fn;
            })
            ->values()
            ->all();
    }

    /**
     * Filter event methods.
     *
     * @param array<int,array<string,mixed>> $functions
     * @return array<int,array<string,mixed>>
     */
    protected function filterEventMethods(array $functions): array
    {
        $allowed = ['dispatch'];
        if (config('core.workflow_blueprint.allowed_event_broadcast', false)) {
            $allowed[] = 'broadcast';
        }

        return collect($functions)
            ->filter(fn($fn) => isset($fn['name']) && in_array($fn['name'], $allowed, true))
            ->map(function ($fn) {
                $fn['ui'] = $fn['ui'] ?? ['hidden' => false];
                return $fn;
            })
            ->values()
            ->all();
    }

    /**
     * Filter command methods to handle only.
     *
     * @param array<int,array<string,mixed>> $functions
     * @return array<int,array<string,mixed>>
     */
    protected function filterCommandMethods(array $functions): array
    {
        $allowed = ['handle'];

        return collect($functions)
            ->filter(fn($fn) => isset($fn['name']) && in_array($fn['name'], $allowed, true))
            ->map(function ($fn) {
                $fn['ui'] = $fn['ui'] ?? ['hidden' => false];
                return $fn;
            })
            ->values()
            ->all();
    }

    /**
     * Apply callable policy to hide disallowed functions.
     *
     * @param array<int,array<string,mixed>> $functions
     * @return array<int,array<string,mixed>>
     */
    protected function applyPolicy(array $functions): array
    {
        $deniedPrefixes = (array) config('core.workflow_blueprint.denied_method_prefixes', []);
        $deniedParams = (array) config('core.workflow_blueprint.denied_parameter_types', []);
        $deniedParamsNormalized = array_map(fn($t) => ltrim((string) $t, '\\'), $deniedParams);

        return collect($functions)->map(function ($fn) use ($deniedPrefixes, $deniedParamsNormalized) {
            $fn['ui'] = $fn['ui'] ?? ['hidden' => false];

            $name = (string) ($fn['name'] ?? '');
            foreach ($deniedPrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $fn['ui']['hidden'] = true;
                }
            }

            if (!empty($fn['parameters']) && is_array($fn['parameters'])) {
                foreach ($fn['parameters'] as &$param) {
                    $type = $param['type'] ?? null;
                    $normalizedType = $type ? ltrim((string) $type, '\\') : null;
                    if ($normalizedType && in_array($normalizedType, $deniedParamsNormalized, true)) {
                        $fn['ui']['hidden'] = true;
                        $fn['ui']['disallowed_reason'] = "Requires {$type}";
                        break;
                    }

                    // Enrich parameter metadata
                    $param['ui'] = $param['ui'] ?? [];
                    $param['ui']['optional'] = array_key_exists('default', $param) && $param['default'] !== null;

                    if (($param['name'] ?? '') === 'model' && (!$type || $type === 'mixed')) {
                        $param['ui']['defaultExpr'] = 'root';
                    }

                    if (($param['name'] ?? '') === 'data' && $type === 'array') {
                        $param['ui']['defaultExprPriority'] = [
                            'steps.<prev>.result',
                            'vars.data',
                            [],
                        ];
                    }
                }
                unset($param);
            }

            return $fn;
        })->all();
    }
}
