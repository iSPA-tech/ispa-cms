<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Expression;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Resolves values from nested data structures using dot-notation and bracket paths.
 *
 * Supports arrays, ArrayAccess objects, Eloquent models, Collections, and generic objects,
 * enabling safe traversal of deeply nested workflow context data.
 *
 * @package Cms\Core\Workflows\Expression
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DotPathAccessor
{
    /**
     * Safely resolve a dot/bracket path from a given root value.
     *
     * @param mixed $root
     * @param  string $path
     * @return mixed
     */
    public function get(mixed $root, string $path): mixed
    {
        $segments = $this->tokenizePath($path);
        $current = $root;

        foreach ($segments as $segment) {
            if ($current === null) {
                return null;
            }

            $current = $this->resolveSegment($current, $segment);
        }

        return $current;
    }

    /**
     * @return array<int, string>
     */
    protected function tokenizePath(string $path): array
    {
        $tokens = [];
        $buffer = '';
        $inBracket = false;

        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            $char = $path[$i];

            if ($char === '.' && !$inBracket) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            if ($char === '[') {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                $inBracket = true;
                continue;
            }

            if ($char === ']' && $inBracket) {
                if ($buffer !== '') {
                    $tokens[] = $this->stripQuotes($buffer);
                    $buffer = '';
                }
                $inBracket = false;
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    protected function stripQuotes(string $value): string
    {
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    protected function resolveSegment(mixed $value, string $segment): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value[$segment] ?? null;
        }

        if ($value instanceof \ArrayAccess) {
            return $value->offsetExists($segment) ? $value[$segment] : null;
        }

        if ($value instanceof Collection || $value instanceof EloquentCollection) {
            if ($value->offsetExists($segment)) {
                return $value->get($segment);
            }
            $index = is_numeric($segment) ? (int) $segment : $segment;
            return $value[$index] ?? null;
        }

        if ($value instanceof Model) {
            return $value->getAttribute($segment);
        }

        if (is_object($value)) {
            if (isset($value->$segment)) {
                return $value->$segment;
            }

            if (method_exists($value, '__get')) {
                return $value->$segment;
            }

            return null;
        }

        return null;
    }
}
