<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Records;

use LogicException;

/**
 * Registry of record types whose per-record history is exposed in the admin.
 *
 * Bound as a singleton; modules register their types while booting.
 *
 * @package Cms\Core\Records
 * @author  iSPA Team
 */
class RecordRegistry
{
    /** @var array<string, RecordType> Registered types keyed by type key. */
    private array $types = [];

    /**
     * Register a record type.
     *
     * @param  RecordType  $type  Type declaration
     * @return void
     *
     * @throws LogicException When a type with the same key is already registered
     */
    public function register(RecordType $type): void
    {
        if (isset($this->types[$type->key])) {
            throw new LogicException("Record type [{$type->key}] is already registered.");
        }

        $this->types[$type->key] = $type;
    }

    /**
     * Get a registered type by key.
     *
     * @param  string  $key  Type key
     * @return RecordType|null  The type, or null when unknown
     */
    public function get(string $key): ?RecordType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * Keys of all registered types, in registration order.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}
