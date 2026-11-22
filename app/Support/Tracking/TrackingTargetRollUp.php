<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Support\Tracking;

/**
 * Read-time roll-up of one tracked target type into its parent type.
 *
 * Some child pages are tracked as their own targets so no view disappears,
 * while the dashboard leaderboards rank the parent: the child's views are
 * folded into the parent row when the leaderboard is read. A module that owns
 * such a hierarchy registers an implementation in TrackingTargetRollUpRegistry.
 */
interface TrackingTargetRollUp
{
    /**
     * Fully qualified class of the child target type whose views are folded.
     */
    public function childType(): string;

    /**
     * Fully qualified class of the parent target type that receives the views.
     */
    public function parentType(): string;

    /**
     * Parent id for each child id that still has a parent.
     *
     * Child ids missing from the result are orphans and keep their own row.
     *
     * @param array<int, int> $childIds
     * @return array<int, int> Map of child id to parent id.
     */
    public function parentIds(array $childIds): array;
}
