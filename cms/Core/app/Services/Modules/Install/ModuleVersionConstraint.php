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
 * Versions are compared with version_compare() (module versions are CalVer YYYY.MM.NN such as 2026.11.01), except a
 * legacy number: before CalVer (2026-11-22) some Core and module versions had a "month" above 12 (2026.16.2,
 * 2026.16.10). That code is older than every CalVer release, so a legacy version sorts below every CalVer version and
 * never meets a CalVer lower bound (fail closed: such a site must deploy or upgrade Core first). A bound written with a
 * month above 12 (`<2026.13.00`) is compared as a plain number, so it still accepts every 2026.01-2026.12 version.
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
            if (!self::boundHolds($version, $m[2], $php)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when $version is a pre-CalVer number: a four-digit year followed by a month segment above 12.
     */
    public static function isLegacy(string $version): bool
    {
        return preg_match('/^(\d{4})\.(\d+)(?:\.|$)/', trim($version), $m) === 1 && (int) $m[1] >= 2000 && (int) $m[2] > 12;
    }

    /**
     * Order of two concrete versions (-1, 0, 1), with legacy numbers below every CalVer version (among themselves
     * they keep their plain order). Use it, not version_compare(), wherever an install or upgrade decision compares
     * two Core or module versions.
     */
    public static function compare(string $a, string $b): int
    {
        $legacyA = self::isLegacy($a);
        $legacyB = self::isLegacy($b);
        if ($legacyA !== $legacyB) {
            return $legacyA ? -1 : 1;
        }

        return version_compare($a, $b);
    }

    /**
     * Whether $version meets one bound. A legacy version is below any CalVer bound; a bound with a month above 12
     * is a plain numeric bound (see the class comment).
     */
    private static function boundHolds(string $version, string $bound, string $operator): bool
    {
        $order = self::isLegacy($version) && !self::isLegacy($bound) ? -1 : version_compare($version, $bound);

        return match ($operator) {
            '==' => $order === 0,
            '>=' => $order >= 0,
            '>' => $order > 0,
            '<=' => $order <= 0,
            '<' => $order < 0,
            default => false,
        };
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
