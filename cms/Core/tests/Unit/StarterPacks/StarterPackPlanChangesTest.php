<?php

use Cms\Core\Services\StarterPacks\StarterPackPlan;

/**
 * Build a plan whose only part is a configuration summary.
 *
 * @param array<string, int> $counts Section counts.
 */
function sppcPlan(array $counts): StarterPackPlan
{
    $config = ['status' => 'succeeded', 'sections' => ['settings' => ['status' => 'changes', 'counts' => $counts, 'total' => array_sum($counts)]], 'warnings' => 0];

    return new StarterPackPlan('demo', '1.0.0', [], $config, null, null, [], [], []);
}

it('reports no changes when the configuration only differs by updates, which a pack never applies', function (): void {
    expect(sppcPlan(['create' => 0, 'update' => 2, 'unchanged' => 1, 'blocked' => 0, 'skipped' => 0])->hasChanges())->toBeFalse();
});

it('reports changes when the configuration has items to add', function (): void {
    expect(sppcPlan(['create' => 1, 'update' => 0, 'unchanged' => 0, 'blocked' => 0, 'skipped' => 0])->hasChanges())->toBeTrue();
});
