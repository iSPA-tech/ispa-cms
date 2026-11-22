<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use Illuminate\Support\Str;

/**
 * Minimal module version constraints (no composer/semver on the site).
 *
 * Grammar: `*` (any), a comparison (`>=`, `>`, `<=`, `<`, `=`) followed by a version, or a bare version (equality).
 * A comma joins parts with AND. Anything else never matches, so an unknown operator blocks rather than allows.
 * Versions are compared with version_compare() (module versions are CalVer YYYY.MM.NN such as 2026.11.01).
 */
final class ModuleVersionConstraint
{
    /**
     * True when $version satisfies $constraint.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (explode(',', $constraint) as $part) {
            $part = trim($part);
            if ($part === '*') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+(?:\.\d+)*)$/', $part, $m) !== 1) {
                return false;
            }
            $operator = $m[1] === '' ? '=' : $m[1];
            $php = $operator === '=' ? '==' : $operator;
            if (!version_compare($version, $m[2], $php)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise a module `requires` value into module name => constraint.
     *
     * List form (`["Content", "Product"]`) means any version; object form (`{"Content": ">=2026.03.0"}`) carries a
     * constraint. Names are studly-cased the way ModuleController does. Invalid entries are ignored.
     *
     * @param array<array-key, mixed> $requires
     *
     * @return array<string, string>
     */
    public static function parseRequires(array $requires): array
    {
        $out = [];
        foreach ($requires as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $out[Str::studly($value)] = '*';
                }

                continue;
            }
            $out[Str::studly((string) $key)] = is_string($value) && trim($value) !== '' ? trim($value) : '*';
        }

        return $out;
    }

    /**
     * Constraint that a `requires` value puts on $module, or null when it does not require it.
     *
     * Module names are compared without case: manifests write `questionbank` while the module is `QuestionBank`, and
     * studly-casing the entry (`Questionbank`) never equals a name with an inner capital. Use this, not a
     * key lookup in {@see self::parseRequires()}, wherever a module name meets a `requires` entry.
     *
     * @param array<array-key, mixed> $requires list form or object form
     */
    public static function constraintFor(array $requires, string $module): ?string
    {
        foreach (self::parseRequires($requires) as $name => $constraint) {
            if (self::sameModule($name, $module)) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * True when two module names name the same module (case-insensitive, like nwidart's module lookup).
     */
    public static function sameModule(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }
}
