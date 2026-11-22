<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

/**
 * Shared context object passed through the import pipeline.
 *
 * Carries the import definition, options, user ID, and a general-purpose
 * shared data store and FK lookup cache. Accessible by transformers,
 * hooks, and the import engine during row processing.
 *
 * @see CoreImportEngine
 * @see ImportDefinitionInterface
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ImportContext
{
    public array $sharedData = [];
    public array $cache = []; // For FK lookup caching

    /**
     * Initialize the import context with definition, options, and user identifier.
     *
     * @param ImportDefinitionInterface $definition The import definition describing columns and mapping.
     * @param ImportOptions             $options    Import configuration options.
     * @param string                    $user_id    The ID of the user performing the import.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public readonly ImportDefinitionInterface $definition,
        public readonly ImportOptions             $options,
        public readonly string                    $user_id, // Or User object, keeping simple for now
    )
    {
    }

    /**
     * Store a value in the shared data store.
     *
     * @param string $key   The data key.
     * @param mixed  $value The value to store.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->sharedData[$key] = $value;
    }

    /**
     * Retrieve a value from the shared data store.
     *
     * @param string $key     The data key.
     * @param mixed  $default Default value if key is not found.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->sharedData[$key] ?? $default;
    }
}
