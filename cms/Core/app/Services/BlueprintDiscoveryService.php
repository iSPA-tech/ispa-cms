<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionUnionType;
use ReflectionNamedType;
use ReflectionIntersectionType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/**
 * Discovers and catalogs blueprint-eligible service classes for workflow integration.
 *
 * Scans configured directory paths for PHP classes with public methods,
 * optionally filtered by an opt-in $isDiscovery property. Returns a
 * structured catalog of classes, their public methods, parameters, and
 * metadata for the workflow builder UI.
 *
 * @package Cms\Core\Services
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BlueprintDiscoveryService
{
    protected array $paths = [
        'app/Services',
        'app/Jobs',
        'app/Console/Commands',
        'app/Events',
    ];

    /**
     * Scan configured paths and return discovered blueprint class catalog.
     *
     * @return array<int, array<string, mixed>> Array of discovered class entries.
     */
    public function scan(): array
    {
        $results = [];
        $this->paths = config('blueprint.discovery.paths', $this->paths);
        $this->paths = $this->expandPaths($this->paths);
        $optInOnly = (bool)config('blueprint.discovery.opt_in_only', true);
        $warnMissingOptIn = (bool)config(
            'blueprint.discovery.warn_missing_opt_in',
            app()->environment('local', 'staging')
        );

        try {
            foreach ($this->paths as $path) {
                $files = File::allFiles(base_path($path));

                foreach ($files as $file) {
                    $functionNamespace = $this->getFullyQualifiedClassName($file->getRealPath());
                    if (!class_exists($functionNamespace) || str_contains($functionNamespace, 'BlueprintDiscoveryService')) {
                        continue;
                    }

                    $refClass = new ReflectionClass($functionNamespace);
                    if ($refClass->isAbstract()) {
                        continue;
                    }

                    $hasOptIn = $refClass->hasProperty('isDiscovery');
                    $defaultProps = $refClass->getDefaultProperties();
                    $isDiscoveryEnabled = $hasOptIn && ($defaultProps['isDiscovery'] ?? false);

                    if ($optInOnly && !$isDiscoveryEnabled) {
                        if ($warnMissingOptIn && !$hasOptIn) {
                            Log::warning('[BlueprintDiscovery] Missing $isDiscovery=true: ' . $functionNamespace);
                        }
                        continue;
                    }

                    if ($hasOptIn && !$isDiscoveryEnabled) {
                        continue;
                    }

                    $category = $this->resolveCategory($functionNamespace);

                    $functions = collect($refClass->getMethods(ReflectionMethod::IS_PUBLIC))
                        ->filter(fn($m) => !$m->isConstructor() && $m->class === $functionNamespace)
                        ->map(function ($method) {
                            return [
                                'name' => $method->getName(),
                                'parameters' => collect($method->getParameters())->map(function ($param) {
                                    $type = $param->getType();

                                    $typeName = match (true) {
                                        $type instanceof ReflectionNamedType => $type->getName(),
                                        $type instanceof ReflectionUnionType => collect($type->getTypes())->map(fn($t) => $t->getName())->implode('|'),
                                        $type instanceof ReflectionIntersectionType => collect($type->getTypes())->map(fn($t) => $t->getName())->implode('&'),
                                        default => null,
                                    };

                                    return [
                                        'name' => $param->getName(),
                                        'type' => $typeName,
                                        'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                                    ];
                                }),
                            ];
                        })->values();

                    $results[] = [
                        'category' => $category,
                        'class' => $functionNamespace,
                        'short_name' => class_basename($functionNamespace),
                        'description' => $refClass->getDefaultProperties()['description'] ?? null,
                        'display_name' => $refClass->getDefaultProperties()['name'] ?? null,
                        'functions' => $functions,
                    ];
                }
            }
            $this->paths = config('blueprint.discovery.paths', $this->paths);
        } catch (Exception $e) {
        }

        return $results;
    }

    /**
     * Expand glob-style paths (e.g., modules/*\/app/Services) into concrete directories.
     */
    protected function expandPaths(array $paths): array
    {
        $expanded = [];
        foreach ($paths as $path) {
            if (str_contains($path, '*')) {
                $matches = glob(base_path($path), GLOB_ONLYDIR) ?: [];
                foreach ($matches as $match) {
                    // store relative path from base_path
                    $expanded[] = ltrim(str_replace(base_path(), '', $match), DIRECTORY_SEPARATOR);
                }
            } else {
                $expanded[] = $path;
            }
        }
        return array_values(array_unique($expanded));
    }

    protected function getFullyQualifiedClassName(string $path): ?string
    {
        $content = File::get($path);
        $namespace = preg_match('/^namespace\s+(.+?);/m', $content, $matches) ? $matches[1] : null;
        $class = preg_match('/^class\s+(\w+)/m', $content, $matches) ? $matches[1] : null;

        return $namespace && $class ? "$namespace\\$class" : null;
    }

    protected function resolveCategory(string $functionNamespace): string
    {
        $categories = [
            'Services' => 'service',
            'Jobs' => 'job',
            'Commands' => 'command',
            'Events' => 'event',
        ];

        foreach ($categories as $keyword => $category) {
            if (str_contains($functionNamespace, $keyword)) {
                return $category;
            }
        }

        return 'unknown';
    }
}
