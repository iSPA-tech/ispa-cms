<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Support\Tracking;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Module hooks for the tracking dashboard: parent/child roll-ups and labels
 * of target types the core does not know about.
 *
 * Modules register during boot; the admin tracking controller reads the
 * registry and stays free of module-specific code. With nothing registered
 * every leaderboard is a straight read.
 */
#[Singleton]
class TrackingTargetRollUpRegistry
{
    /** @var array<string, TrackingTargetRollUp> Keyed by child type. */
    private array $rollUps = [];

    /** @var array<string, string> Target class basename => translation key. */
    private array $typeLabels = [];

    public function registerRollUp(TrackingTargetRollUp $rollUp): void
    {
        $this->rollUps[$rollUp->childType()] = $rollUp;
    }

    /**
     * @param array<string, string> $labels Target class basename => translation key.
     */
    public function registerTypeLabels(array $labels): void
    {
        $this->typeLabels = $labels + $this->typeLabels;
    }

    /**
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        return $this->typeLabels;
    }

    /**
     * Child types folded into the given parent type.
     *
     * @return array<int, string>
     */
    public function childTypesOf(string $parentType): array
    {
        $children = [];
        foreach ($this->rollUps as $rollUp) {
            if ($rollUp->parentType() === $parentType) {
                $children[] = $rollUp->childType();
            }
        }

        return $children;
    }

    /**
     * Fold every registered child type into its parent rows.
     *
     * Rows are objects with target_type, target_id and views. A child whose
     * parent is known adds its views to the parent row (created when the
     * parent is not in the list); an orphaned child keeps its own row.
     *
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function rollUp(Collection $rows): Collection
    {
        foreach ($this->rollUps as $rollUp) {
            $rows = $this->rollUpOne($rollUp, $rows);
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rollUpOne(TrackingTargetRollUp $rollUp, Collection $rows): Collection
    {
        $childClass = $rollUp->childType();
        $parentClass = $rollUp->parentType();

        [$children, $others] = $rows->partition(
            static fn (object $row): bool => (string) $row->target_type === $childClass
        );

        if ($children->isEmpty()) {
            return $rows;
        }

        $childToParent = $rollUp->parentIds(
            $children->pluck('target_id')->map(static fn (mixed $id): int => (int) $id)->all()
        );

        $byParent = $others->keyBy(
            static fn (object $row): string => $row->target_type . ':' . $row->target_id
        );

        foreach ($children as $child) {
            $parentId = $childToParent[(int) $child->target_id] ?? null;
            if ($parentId === null) {
                //  Orphaned child (parent row deleted): keep its own line
                //  rather than dropping the views.
                $byParent->put($childClass . ':' . $child->target_id, $child);
                continue;
            }

            $key = $parentClass . ':' . $parentId;
            $existing = $byParent->get($key);

            if ($existing !== null) {
                $existing->views = (int) $existing->views + (int) $child->views;
            } else {
                $byParent->put($key, (object) [
                    'target_type' => $parentClass,
                    'target_id' => (int) $parentId,
                    'views' => (int) $child->views,
                ]);
            }
        }

        return $byParent->values();
    }
}
