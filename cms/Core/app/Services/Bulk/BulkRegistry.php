<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk;

use Closure;
use InvalidArgumentException;
use Illuminate\Contracts\Container\Container;

/**
 * Registry for bulk action handlers, supporting lazy resolution.
 *
 * Handlers can be registered as concrete instances, closures, or
 * class names. Resolves handlers on first access via the container
 * and caches instances for subsequent calls.
 *
 * @see BulkActionHandler
 * @package Cms\Core\Services\Bulk
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BulkRegistry
{
    /** @var array<string, BulkActionHandler|Closure|string> */
    protected array $definitions = [];

    /** @var array<string, BulkActionHandler> */
    protected array $instances = [];

    /**
     * Initialize the registry with the service container for lazy handler resolution.
     *
     * @param Container $container The application container for resolving handler classes.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Register a bulk action handler by key.
     *
     * @param string                                                     $key     Unique handler key.
     * @param BulkActionHandler|Closure|class-string<BulkActionHandler> $handler The handler instance, factory, or class name.
     * @return void
     */
    public function register(string $key, BulkActionHandler|Closure|string $handler): void
    {
        if ($handler instanceof BulkActionHandler) {
            $this->instances[$key] = $handler;
            return;
        }

        $this->definitions[$key] = $handler;
    }

    /**
     * Check if a handler is registered for the given key.
     *
     * @param string $key The handler key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->instances[$key]) || isset($this->definitions[$key]);
    }

    /**
     * Resolve and return the handler for the given key.
     *
     * @param string $key The handler key.
     * @return BulkActionHandler|null The resolved handler, or null if not registered.
     *
     * @throws InvalidArgumentException When the resolved handler does not implement BulkActionHandler.
     */
    public function resolve(string $key): ?BulkActionHandler
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (!isset($this->definitions[$key])) {
            return null;
        }

        $definition = $this->definitions[$key];
        $handler = $definition instanceof Closure
            ? $this->container->call($definition)
            : $this->container->make($definition);

        if (!$handler instanceof BulkActionHandler) {
            throw new InvalidArgumentException("Bulk handler for {$key} must implement BulkActionHandler.");
        }

        $this->instances[$key] = $handler;

        return $handler;
    }
}
