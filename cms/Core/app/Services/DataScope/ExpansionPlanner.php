<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

/**
 * The single place that turns held nodes into an ExpansionPlan (self nodes + expansion anchors).
 *
 * Scope rule:
 * - Only this class computes anchors. Ancestors or path segments of a held node never become anchors (the VTASCM
 *   leak: a department root used as an anchor exposed the whole department).
 * - `self` adds the node to selfNodeIds; no anchor.
 * - `descendants` anchors the node itself.
 * - `branch` anchors the node's parent when that parent exists and is not a root (parentOf(parent) === null means
 *   root); otherwise it anchors the node itself. A branch never reaches a root: to see a
 *   root, hold the root with `descendants`. A branch held on a root node itself therefore counts as `self`.
 * - Visible nodes = held `self` nodes ∪ subtree of each anchor (anchors included).
 * - Fail closed: an unknown or soft-deleted node (exists() false) contributes nothing; a node without hierarchy
 *   data (hasHierarchyData() false, e.g. a missing closure self row) counts as `self` whatever its expansion.
 * - Flat hierarchies (FlatHierarchy: parentOf() always null, subtree = anchors) need no special case: a
 *   `descendants` node becomes an anchor whose subtree is itself and a `branch` node is a root, so it counts as
 *   `self`. Either way the visible set equals the `self` case.
 * - Ids in the plan are unique and sorted ascending.
 */
final class ExpansionPlanner
{
    /**
     * Build the plan for the given held nodes over one hierarchy.
     *
     * @param array<int, HeldNode> $held
     */
    public function plan(array $held, HierarchyProvider $hierarchy): ExpansionPlan
    {
        $self = [];
        $anchors = [];

        foreach ($held as $node) {
            $id = $node->nodeId;

            if (! $hierarchy->exists($id)) {
                continue;
            }

            if ($node->expansion === Expansion::SelfOnly || ! $hierarchy->hasHierarchyData($id)) {
                $self[$id] = $id;

                continue;
            }

            if ($node->expansion === Expansion::Descendants) {
                $anchors[$id] = $id;

                continue;
            }

            $anchor = $this->branchAnchor($id, $hierarchy);

            if ($anchor === null) {
                $self[$id] = $id;
            } else {
                $anchors[$anchor] = $anchor;
            }
        }

        sort($self);
        sort($anchors);

        return new ExpansionPlan(array_values($held), array_values($self), array_values($anchors));
    }

    /**
     * Anchor for a `branch` holding: the non-root parent, else the node itself; null when the node is a root.
     */
    private function branchAnchor(int $id, HierarchyProvider $hierarchy): ?int
    {
        $parent = $hierarchy->parentOf($id);

        if ($parent === null) {
            return null;
        }

        // A missing parent or a root parent never becomes an anchor; the node anchors itself instead.
        if (! $hierarchy->exists($parent) || ! $hierarchy->hasHierarchyData($parent) || $hierarchy->parentOf($parent) === null) {
            return $id;
        }

        return $parent;
    }
}
