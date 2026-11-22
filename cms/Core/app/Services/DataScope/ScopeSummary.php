<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use App\Models\User;

/**
 * Readable summary of the data scope a user holds for one permission key, for approvals and the user screen.
 *
 * Shape: `{ level, own, dimensions: [{ name, label, label_key, nodes: [{ key, label, trail, expansion }], more }] }`.
 * Level: `all` (no dimensions listed), `none` (empty scope), `own` (own only), `nodes` (dimension nodes only),
 * `mixed` (own plus dimension nodes). Nodes are the HELD nodes of each plan (not the expanded subtree), one entry per
 * node (the widest expansion wins when a node is held several times), skipping nodes the provider no longer knows,
 * sorted by label, at most MAX_NODES per dimension with `more` counting the rest. Nodes are identified by the
 * provider's stable key; the output never carries node ids or user ids. The dimension `label` is frozen in the locale
 * active when the summary is taken, so `label_key` carries the translation key for readers that render in their own locale
 * (snapshots stored before `label_key` existed only have `label`).
 */
class ScopeSummary
{
    /**
     * Maximum number of nodes listed per dimension.
     */
    public const MAX_NODES = 50;

    /**
     * Expansion breadth used to keep the widest holding of a node.
     */
    protected const BREADTH = ['self' => 0, 'descendants' => 1, 'branch' => 2];

    /**
     * @param ScopeResolver $resolver Resolves user × permission key to a ResolvedScope.
     * @param DimensionRegistry $dimensions Registered dimensions, their labels and hierarchy providers.
     */
    public function __construct(
        protected ScopeResolver $resolver,
        protected DimensionRegistry $dimensions,
    ) {
    }

    /**
     * Summarise the user's scope for the permission key.
     *
     * @return array{level: string, own: bool, dimensions: array<int, array{name: string, label: string, label_key: string, nodes: array<int, array{key: string, label: string, trail: array<int, string>, expansion: string}>, more: int}>}
     */
    public function for(User $user, string $permissionKey): array
    {
        $scope = $this->resolver->for($user, $permissionKey);

        if ($scope->all) {
            return ['level' => 'all', 'own' => $scope->own, 'dimensions' => []];
        }

        if ($scope->isEmpty()) {
            return ['level' => 'none', 'own' => false, 'dimensions' => []];
        }

        $dimensions = [];
        $meta = $this->dimensions->all();

        foreach ($scope->plans as $name => $plan) {
            $provider = $this->dimensions->provider($name);

            if ($provider === null || ! isset($meta[$name])) {
                continue;
            }

            $summary = $this->dimension($plan, $provider);

            if ($summary['nodes'] === []) {
                continue;
            }

            $dimensions[] = [
                'name' => $name,
                'label' => (string) __($meta[$name]['label_key']),
                'label_key' => $meta[$name]['label_key'],
            ] + $summary;
        }

        $level = match (true) {
            $dimensions === [] => $scope->own ? 'own' : 'none',
            $scope->own => 'mixed',
            default => 'nodes',
        };

        return ['level' => $level, 'own' => $scope->own, 'dimensions' => $dimensions];
    }

    /**
     * Listed held nodes of one plan (capped) and the count of the rest.
     *
     * @return array{nodes: array<int, array{key: string, label: string, trail: array<int, string>, expansion: string}>, more: int}
     */
    protected function dimension(ExpansionPlan $plan, HierarchyProvider $provider): array
    {
        $expansions = [];

        foreach ($plan->held as $node) {
            $current = $expansions[$node->nodeId] ?? null;

            if ($current === null || self::BREADTH[$node->expansion->value] > self::BREADTH[$current]) {
                $expansions[$node->nodeId] = $node->expansion->value;
            }
        }

        $labels = $provider->labels(array_keys($expansions));
        $candidates = [];

        foreach ($labels as $id => $label) {
            $key = $provider->keyFor($id);

            if ($key !== null) {
                $candidates[] = ['id' => $id, 'key' => $key, 'label' => $label];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['label'], $a['key']] <=> [$b['label'], $b['key']]);

        $nodes = [];

        foreach (array_slice($candidates, 0, self::MAX_NODES) as $candidate) {
            $nodes[] = [
                'key' => $candidate['key'],
                'label' => $candidate['label'],
                'trail' => $provider->trail($candidate['id']),
                'expansion' => $expansions[$candidate['id']],
            ];
        }

        return ['nodes' => $nodes, 'more' => max(0, count($candidates) - self::MAX_NODES)];
    }
}
