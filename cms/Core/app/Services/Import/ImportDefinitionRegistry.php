<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

use Illuminate\Contracts\Container\Container;

/**
 * Registry for import definitions keyed by module and entity name.
 *
 * Modules register their import definition classes during boot, and
 * the CoreImportEngine resolves them at import time via this registry.
 *
 * @see ImportDefinitionInterface
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ImportDefinitionRegistry
{
    /**
     * @var array<string, array<string, string>>
     */
    protected array $definitions = [];

    /**
     * Initialize the registry with the service container for lazy definition resolution.
     *
     * @param Container $container The application container for resolving definition classes.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected Container $container
    ) {}

    /**
     * Register an import definition class for a module/entity pair.
     *
     * @param string $module          The module name (e.g., 'Education').
     * @param string $entity          The entity name (e.g., 'instructors').
     * @param string $definitionClass The ImportDefinitionInterface class name.
     * @return void
     */
    public function register(string $module, string $entity, string $definitionClass): void
    {
        $key = $this->makeKey($module, $entity);
        $this->definitions[$key] = $definitionClass;
    }

    /**
     * Resolve an import definition instance from the registry.
     *
     * @param string $module The module name.
     * @param string $entity The entity name.
     * @return ImportDefinitionInterface|null The resolved definition, or null if not registered.
     */
    public function resolve(string $module, string $entity): ?ImportDefinitionInterface
    {
        $key = $this->makeKey($module, $entity);
        
        if (!isset($this->definitions[$key])) {
            return null;
        }

        $definitionClass = $this->definitions[$key];
        
        return $this->container->make($definitionClass);
    }

    /**
     * Check if a definition exists for the given module/entity pair.
     *
     * @param string $module The module name.
     * @param string $entity The entity name.
     * @return bool
     */
    public function has(string $module, string $entity): bool
    {
        $key = $this->makeKey($module, $entity);
        return isset($this->definitions[$key]);
    }

    /**
     * Get all registered definition classes keyed by module.entity.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Make a registry key from module and entity
     */
    protected function makeKey(string $module, string $entity): string
    {
        return strtolower($module) . '.' . strtolower($entity);
    }
}
