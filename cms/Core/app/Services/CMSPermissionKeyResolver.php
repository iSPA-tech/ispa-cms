<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

/**
 * Permission key resolver with alias/fallback support.
 *
 * Supports both new module-prefixed permission keys and legacy keys:
 * - New format: "module.resource.ability" (e.g., "education.research-period.delete")
 * - Legacy format: "resource.ability" (e.g., "research-period.delete")
 *
 * When checking a new format permission, the resolver generates candidate keys:
 * 1. Primary: the full new format key
 * 2. Fallback: the legacy format key (without module prefix)
 *
 * This allows gradual migration to new permission keys while maintaining
 * backward compatibility with existing database permissions.
 *
 * @package Cms\Core\Services
 * @author iSPA Team
 * @since 8.0.0 (2026-03-17)
 */
class CMSPermissionKeyResolver
{
    /**
     * Parse a permission key into its components.
     *
     * @param string $key The permission key to parse
     * @return array{module: string|null, group: string, name: string}
     */
    public static function parse(string $key): array
    {
        $parts = explode('.', $key);
        $count = count($parts);

        if ($count >= 3) {
            // New format: module.resource.ability or module.resource-part.ability
            // The last part is always the ability (name)
            // Everything before the last part is the group
            $name = array_pop($parts);
            $group = implode('.', $parts);

            // Extract module (first part) for fallback resolution
            $module = $parts[0];
            $resourceParts = array_slice($parts, 1);
            $resource = implode('.', $resourceParts);

            return [
                'module' => $module,
                'group' => $group,
                'name' => $name,
                'resource' => $resource,
            ];
        }

        if ($count === 2) {
            // Legacy format: resource.ability
            return [
                'module' => null,
                'group' => $parts[0],
                'name' => $parts[1],
                'resource' => $parts[0],
            ];
        }

        // Single part or empty - treat as legacy with empty group
        return [
            'module' => null,
            'group' => '',
            'name' => $key,
            'resource' => '',
        ];
    }

    /**
     * Get all candidate permission keys to check (with fallback).
     *
     * For new format keys, returns both the new key and legacy fallback.
     * For legacy format keys, returns only the original key.
     *
     * @param string $key The permission key to resolve
     * @return array<string> List of candidate keys to check (primary first, then fallbacks)
     */
    public static function getCandidates(string $key): array
    {
        $parsed = self::parse($key);
        $candidates = [];

        // Primary: the original key as-is
        $primaryKey = $parsed['group'] . '.' . $parsed['name'];
        if ($parsed['group'] !== '') {
            $candidates[] = $primaryKey;
        } else {
            $candidates[] = $parsed['name'];
        }

        // Fallback: if this is a new format (has module prefix), also check legacy format
        if ($parsed['module'] !== null && $parsed['resource'] !== '') {
            $legacyKey = $parsed['resource'] . '.' . $parsed['name'];
            if ($legacyKey !== $primaryKey) {
                $candidates[] = $legacyKey;
            }
        }

        return array_unique($candidates);
    }

    /**
     * Check if a permission key matches any of the user's permissions,
     * including fallback to legacy format.
     *
     * @param string $key The permission key to check
     * @param array<string> $userPermissions The user's permission keys
     * @return bool True if any candidate key matches
     */
    public static function hasPermission(string $key, array $userPermissions): bool
    {
        $candidates = self::getCandidates($key);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the legacy (non-prefixed) version of a permission key.
     *
     * @param string $key The permission key
     * @return string The legacy format key
     */
    public static function toLegacy(string $key): string
    {
        $parsed = self::parse($key);

        if ($parsed['module'] !== null && $parsed['resource'] !== '') {
            return $parsed['resource'] . '.' . $parsed['name'];
        }

        return $key;
    }

    /**
     * Convert a legacy permission key to the new module-prefixed format.
     *
     * @param string $legacyKey The legacy permission key (e.g., "research-period.delete")
     * @param string $module The module prefix to add (e.g., "education")
     * @return string The new format key (e.g., "education.research-period.delete")
     */
    public static function toModulePrefixed(string $legacyKey, string $module): string
    {
        $parsed = self::parse($legacyKey);

        // Only add module prefix if not already present
        if ($parsed['module'] === null) {
            return $module . '.' . $legacyKey;
        }

        return $legacyKey;
    }
}
