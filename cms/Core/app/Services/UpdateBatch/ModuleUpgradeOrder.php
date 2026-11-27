<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\UpdateBatch;

use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;

/**
 * Order in which the modules of an upgrade-all run are upgraded (spec 2026-11-26, decision 3).
 *
 * A module goes before every batch module whose target version requires a version of it that is not installed yet
 * (Account before SocialAuth, which needs Account >= 2026.11.05; an unknown installed version counts as not met). A module
 * whose installed version pins another batch module against that module's target goes first, when its own target no
 * longer pins it. Among modules that are ready, name order. Modules that wait on each other are reported as a cycle
 * and left out of the order. The order only saves skipped items: each module still gets its own preflight at its turn.
 */
final class ModuleUpgradeOrder
{
    /**
     * @param array<string, array{version: string, requires: array<array-key, mixed>}> $targets           batch modules and their target version entry
     * @param array<string, array<array-key, mixed>>                                   $installedRequires `requires` of each batch module's installed copy
     * @param array<string, string|null>                                              $installedVersions installed version of each batch module (null: unknown)
     *
     * @return array{ordered: list<string>, cyclic: list<string>}
     */
    public function sort(array $targets, array $installedRequires, array $installedVersions = []): array
    {
        $names = array_map('strval', array_keys($targets));
        // $after[$a][$b] = true: $a must wait for $b.
        $after = array_fill_keys($names, []);
        foreach ($names as $module) {
            foreach ($names as $other) {
                if ($module === $other) {
                    continue;
                }
                if ($this->needsNewer($targets[$module]['requires'], $other, $installedVersions[$other] ?? null)) {
                    $after[$module][$other] = true;
                }
                if ($this->installedPinBlocks($module, $other, $targets, $installedRequires[$module] ?? [])) {
                    // The installed $module refuses the target of $other: $other waits until $module moved.
                    $after[$other][$module] = true;
                }
            }
        }

        $ordered = [];
        $left = $names;
        while ($left !== []) {
            $ready = array_values(array_filter($left, static fn (string $name): bool => array_diff(array_keys($after[$name]), $ordered) === []));
            if ($ready === []) {
                break;
            }
            usort($ready, 'strcasecmp');
            $ordered[] = $ready[0];
            $left = array_values(array_diff($left, [$ready[0]]));
        }

        return ['ordered' => $ordered, 'cyclic' => $left];
    }

    /**
     * Whether a target `requires` names $other with a constraint its installed version does not meet (or is unknown).
     *
     * @param array<array-key, mixed> $requires
     */
    private function needsNewer(array $requires, string $other, ?string $installed): bool
    {
        $constraint = ModuleVersionConstraint::constraintFor($requires, $other);

        return $constraint !== null && ($installed === null || ! ModuleVersionConstraint::satisfies($installed, $constraint));
    }

    /**
     * Whether the installed $module rejects the target of $other while the target of $module accepts it.
     *
     * @param array<string, array{version: string, requires: array<array-key, mixed>}> $targets
     * @param array<array-key, mixed>                                                  $installed
     */
    private function installedPinBlocks(string $module, string $other, array $targets, array $installed): bool
    {
        $pin = ModuleVersionConstraint::constraintFor($installed, $other);
        if ($pin === null || ModuleVersionConstraint::satisfies($targets[$other]['version'], $pin)) {
            return false;
        }
        $next = ModuleVersionConstraint::constraintFor($targets[$module]['requires'], $other);

        return $next === null || ModuleVersionConstraint::satisfies($targets[$other]['version'], $next);
    }
}
