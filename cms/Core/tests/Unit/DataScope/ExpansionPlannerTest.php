<?php

declare(strict_types=1);

use Cms\Core\Services\DataScope\Expansion;
use Cms\Core\Services\DataScope\ExpansionPlan;
use Cms\Core\Services\DataScope\ExpansionPlanner;
use Cms\Core\Services\DataScope\HeldNode;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;

uses(\Cms\Core\Tests\TestCase::class, ScopeTreeFixture::class);

beforeEach(function (): void {
    $this->createScopeTreeFixture();
});

/**
 * Plan the given [code => Expansion] holdings over the fixture closure hierarchy.
 *
 * @param array<int, array{0: string|int, 1: Expansion}> $holdings
 */
function dataScopeExpansionPlan(object $test, array $holdings, ?object $hierarchy = null): array
{
    $hierarchy ??= $test->scopeClosureHierarchy();
    $held = array_map(
        fn (array $h): HeldNode => new HeldNode(is_int($h[0]) ? $h[0] : $test->nodeId($h[0]), $h[1], 'user', 1),
        $holdings,
    );

    $plan = (new ExpansionPlanner())->plan($held, $hierarchy);

    return [
        'plan' => $plan,
        'self' => $test->scopeCodes($plan->selfNodeIds),
        'anchors' => $test->scopeCodes($plan->anchorIds),
        'visible' => $test->scopeCodes($plan->visibleIds($hierarchy)),
    ];
}

it('expands descendants from the held node only', function (): void {
    $r = dataScopeExpansionPlan($this, [['MKT_HOTTOY', Expansion::Descendants]]);

    expect($r['plan'])->toBeInstanceOf(ExpansionPlan::class)
        ->and($r['self'])->toBe([])
        ->and($r['anchors'])->toBe(['MKT_HOTTOY'])
        ->and($r['visible'])->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY'])
        ->and($r['visible'])->not->toContain('MKT', 'MKT_HOBBY', 'HOBBY_A', 'TRADE', 'TRADE_X');
});

it('anchors a branch at the non-root parent', function (): void {
    $r = dataScopeExpansionPlan($this, [['HOTTOY_A', Expansion::Branch]]);

    expect($r['self'])->toBe([])
        ->and($r['anchors'])->toBe(['MKT_HOTTOY'])
        ->and($r['visible'])->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']);
});

it('never lets a branch reach a root parent', function (): void {
    $r = dataScopeExpansionPlan($this, [['MKT_HOTTOY', Expansion::Branch]]);

    expect($r['anchors'])->toBe(['MKT_HOTTOY'])
        ->and($r['visible'])->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']);
});

it('treats a branch held on a root as self', function (): void {
    $r = dataScopeExpansionPlan($this, [['MKT', Expansion::Branch]]);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['MKT'])
        ->and($r['visible'])->toBe(['MKT']);
});

it('keeps self holdings to the node itself', function (): void {
    $r = dataScopeExpansionPlan($this, [['HOTTOY_A', Expansion::SelfOnly]]);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['HOTTOY_A'])
        ->and($r['visible'])->toBe(['HOTTOY_A']);
});

it('shows a whole root tree when the root is held with descendants', function (): void {
    $r = dataScopeExpansionPlan($this, [['MKT', Expansion::Descendants]]);

    expect($r['anchors'])->toBe(['MKT'])
        ->and($r['visible'])->toBe(['HOBBY_A', 'HOTTOY_A', 'HOTTOY_B', 'MKT', 'MKT_HOBBY', 'MKT_HOTTOY']);
});

it('unions several self holdings exactly', function (): void {
    $r = dataScopeExpansionPlan($this, [['HOTTOY_A', Expansion::SelfOnly], ['TRADE_X', Expansion::SelfOnly]]);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['HOTTOY_A', 'TRADE_X'])
        ->and($r['visible'])->toBe(['HOTTOY_A', 'TRADE_X']);
});

it('ignores soft-deleted and unknown nodes', function (Expansion $expansion): void {
    $this->softDelete('HOBBY_A');

    $r = dataScopeExpansionPlan($this, [['HOBBY_A', $expansion], [999999, $expansion]]);

    expect($r['self'])->toBe([])
        ->and($r['anchors'])->toBe([])
        ->and($r['visible'])->toBe([]);
})->with([Expansion::SelfOnly, Expansion::Descendants, Expansion::Branch]);

it('counts a node without hierarchy data as self', function (Expansion $expansion): void {
    $this->deleteClosureRows('MKT_HOTTOY');

    $r = dataScopeExpansionPlan($this, [['MKT_HOTTOY', $expansion]]);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['MKT_HOTTOY'])
        ->and($r['visible'])->toBe(['MKT_HOTTOY']);
})->with([Expansion::Descendants, Expansion::Branch]);

it('never anchors a root unless that root is held with descendants', function (): void {
    $hierarchy = $this->scopeClosureHierarchy();
    $roots = ['MKT', 'TRADE'];

    foreach (array_keys(self::scopeTreeShape()) as $code) {
        foreach (Expansion::cases() as $expansion) {
            $r = dataScopeExpansionPlan($this, [[$code, $expansion]], $hierarchy);
            $expectedRoots = in_array($code, $roots, true) && $expansion === Expansion::Descendants ? [$code] : [];

            expect(array_values(array_intersect($r['anchors'], $roots)))
                ->toBe($expectedRoots, "{$code} held with {$expansion->value}");
        }
    }
});

it('dedupes and sorts plan ids', function (): void {
    $r = dataScopeExpansionPlan($this, [
        ['TRADE_X', Expansion::SelfOnly],
        ['HOTTOY_A', Expansion::SelfOnly],
        ['TRADE_X', Expansion::SelfOnly],
        ['HOTTOY_B', Expansion::Branch],
        ['HOTTOY_A', Expansion::Branch],
    ]);

    $self = $r['plan']->selfNodeIds;
    $sorted = $self;
    sort($sorted);

    expect($self)->toBe($sorted)
        ->and($r['plan']->anchorIds)->toBe([$this->nodeId('MKT_HOTTOY')])
        ->and($r['self'])->toBe(['HOTTOY_A', 'TRADE_X'])
        ->and($r['plan']->held)->toHaveCount(5);
});

it('gives the self result for every expansion over a flat hierarchy', function (Expansion $expansion): void {
    $flat = $this->scopeFlatHierarchy();
    $self = dataScopeExpansionPlan($this, [['MKT_HOTTOY', Expansion::SelfOnly]], $flat);
    $r = dataScopeExpansionPlan($this, [['MKT_HOTTOY', $expansion]], $flat);

    expect($r['visible'])->toBe($self['visible'])
        ->and($r['visible'])->toBe(['MKT_HOTTOY']);
})->with([Expansion::Descendants, Expansion::Branch]);

it('returns no rows for an empty plan', function (): void {
    $hierarchy = $this->scopeClosureHierarchy();
    $plan = (new ExpansionPlanner())->plan([], $hierarchy);

    expect($plan->visibleIdsQuery($hierarchy)->get()->all())->toBe([])
        ->and($plan->visibleIds($hierarchy))->toBe([]);
});

it('rejects an unknown holding source', function (): void {
    new HeldNode(1, Expansion::SelfOnly, 'team', 1);
})->throws(InvalidArgumentException::class);
