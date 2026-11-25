<?php

declare(strict_types=1);

use Cms\Core\Services\Workflow\Versioning\WorkflowSensitiveFingerprint;

/*
 * Auto-publish after JEV calibration: `jev.min_confidence`, `jev.verdict_from` and `jev.hold_if` join the approval
 * step's entry of the sensitive fingerprint; switching to `jev` and lowering the level are sensitive changes.
 */

uses(Cms\Core\Tests\TestCase::class);

/** review (approval) → tell (notify, a write the approval guards). */
function afGraph(array $approval): array
{
    return [
        'steps' => [
            ['step_code' => 'review', 'step_type' => 'approval', 'step_config' => $approval + ['payload' => "'x'"], 'is_start' => true],
            ['step_code' => 'tell', 'step_type' => 'notify', 'step_config' => ['permission' => 'agentic.approve', 'message' => 'Done']],
        ],
        'connections' => [['from' => 'review', 'to' => 'tell']],
        'settings' => [],
    ];
}

function afChanges(array $before, array $after): array
{
    return WorkflowSensitiveFingerprint::changes(
        WorkflowSensitiveFingerprint::of(afGraph($before))['summary'],
        WorkflowSensitiveFingerprint::of(afGraph($after))['summary'],
    );
}

$learning = ['mode' => 'human', 'jev' => ['verdict_from' => 'vars.jev', 'min_confidence' => 0.8, 'hold_if' => 'vars.flagged']];

it('puts the auto-publish settings in the approval entry only when the step sets them', function () use ($learning): void {
    expect(WorkflowSensitiveFingerprint::of(afGraph($learning))['summary']['approvals']['review'])->toBe([
        'mode' => 'human', 'reversible_skip' => true,
        // No JEV judge step stores vars.jev in this graph: no producer (the policy refuses it), no other writer.
        'jev' => ['min_confidence' => 0.8, 'verdict_from' => 'vars.jev', 'hold_if' => 'vars.flagged', 'producer' => [], 'writers' => []],
    ])->and(WorkflowSensitiveFingerprint::of(afGraph(['mode' => 'human']))['summary']['approvals']['review'])->toBe(['mode' => 'human', 'reversible_skip' => true]);
});

it('reports switching auto-publish on and lowering its level, not raising it', function () use ($learning): void {
    $on = ['mode' => 'jev'] + $learning;
    $lower = ['mode' => 'jev', 'jev' => ['min_confidence' => 0.6] + $learning['jev']];
    $higher = ['mode' => 'jev', 'jev' => ['min_confidence' => 0.9] + $learning['jev']];

    // (`jev` mode also carries the node's ai_spend safety class, reported as a sensitive step.)
    expect(collect(afChanges($learning, $on))->firstWhere('kind', 'approval_mode_changed'))->toBe(['kind' => 'approval_mode_changed', 'step_code' => 'review', 'detail' => 'human -> jev'])
        ->and(afChanges($on, $lower))->toBe([['kind' => 'approval_mode_changed', 'step_code' => 'review', 'detail' => 'jev.min_confidence 0.8 -> 0.6']])
        ->and(afChanges($on, $higher))->toBe([])
        ->and(array_column(afChanges($on, ['mode' => 'jev', 'jev' => ['hold_if' => 'false'] + $learning['jev']]), 'detail'))->toBe(['jev.hold_if changed'])
        // A hash change while learning is not a sensitive change; switching off is not either.
        ->and(afChanges(['mode' => 'human'], $learning))->toBe([])
        ->and(array_column(afChanges($on, $learning), 'kind'))->not->toContain('approval_mode_changed')
        ->and(WorkflowSensitiveFingerprint::of(afGraph($on))['hash'])->not->toBe(WorkflowSensitiveFingerprint::of(afGraph($lower))['hash']);
});
