<?php

declare(strict_types=1);

use Cms\Core\Services\Workflow\Setup\WorkflowGraphLayout;

/*
 * Expected positions are what the designer's "Tidy up" (base-admin graphLayout.js layoutGraph, cards unmeasured)
 * gives for the same graph: 300 x 96 cards from (40, 32), rows 160 apart, a branch's outputs 348 apart.
 */

it('stacks a straight flow in one column from the origin, top to bottom', function (): void {
    expect(WorkflowGraphLayout::tidy(['a', 'b', 'c'], [['a', 'b'], ['b', 'c']]))
        ->toBe(['a' => [40, 32], 'b' => [40, 192], 'c' => [40, 352]]);
});

it('puts the next steps of a branch side by side in the order of its outputs, and a join under both', function (): void {
    $positions = WorkflowGraphLayout::tidy(
        ['start', 'ask', 'yes', 'no', 'join'],
        [['start', 'ask', null], ['ask', 'yes', 'true'], ['ask', 'no', 'false'], ['yes', 'join', null], ['no', 'join', null]],
    );

    expect($positions)->toBe([
        'start' => [214, 32], 'ask' => [214, 192], 'yes' => [40, 352], 'join' => [214, 512], 'no' => [388, 352],
    ]);
});

it('puts the error output after the other outputs of a step, whatever order the links come in', function (): void {
    expect(WorkflowGraphLayout::tidy(['a', 'fail', 'ok'], [['a', 'fail', 'error'], ['a', 'ok', null]]))
        ->toBe(['a' => [214, 32], 'ok' => [40, 192], 'fail' => [388, 192]]);
});

it('leaves the link back to a loop out of the rows and puts what follows the loop below the steps it repeats', function (): void {
    $positions = WorkflowGraphLayout::tidy(
        ['start', 'each', 'body', 'done'],
        [['start', 'each', null], ['each', 'body', 'loop'], ['body', 'each', null], ['each', 'done', 'done']],
    );

    // Room for the loop frame: its title above the first repeated step (48), its padding below the last (24).
    expect($positions)->toBe(['start' => [214, 32], 'each' => [214, 192], 'body' => [40, 400], 'done' => [388, 584]]);
});

it('ignores links to unknown steps and to the step itself, and starts a column for a step nothing leads to', function (): void {
    expect(WorkflowGraphLayout::tidy(['a', 'b', 'alone'], [['a', 'b'], ['a', 'a'], ['a', 'ghost']]))
        ->toBe(['a' => [40, 32], 'b' => [40, 192], 'alone' => [388, 32]])
        ->and(WorkflowGraphLayout::tidy([], []))->toBe([]);
});
