<?php

declare(strict_types=1);

use Cms\Core\Services\DataScope\Expansion;
use Cms\Core\Services\DataScope\ExpansionPlanner;
use Cms\Core\Services\DataScope\HeldNode;
use Cms\Core\Services\DataScope\HierarchyProvider;
use Cms\Core\Services\DataScope\PathHierarchy;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, ScopeTreeFixture::class);

beforeEach(function (): void {
    $this->createScopeTreeFixture();
    $this->hierarchy = new PathHierarchy(
        table: 'scope_fixture_nodes',
        idColumn: 'id',
        keyColumn: 'code',
        labelColumn: 'name',
        pathColumn: 'path',
    );
});

/**
 * Plan the given [code|id, Expansion] holdings over a hierarchy and return the plan as fixture codes.
 *
 * @param array<int, array{0: string|int, 1: Expansion}> $holdings
 * @return array{self: array<int, string>, anchors: array<int, string>, visible: array<int, string>}
 */
function pathScopePlan(object $test, array $holdings, HierarchyProvider $hierarchy): array
{
    $held = array_map(
        fn (array $h): HeldNode => new HeldNode(is_int($h[0]) ? $h[0] : $test->nodeId($h[0]), $h[1], 'user', 1),
        $holdings,
    );
    $plan = (new ExpansionPlanner())->plan($held, $hierarchy);
    $visible = $plan->visibleIdsQuery($hierarchy)->pluck('node_id')->map(fn ($id): int => (int) $id)->all();

    return [
        'self' => $test->scopeCodes($plan->selfNodeIds),
        'anchors' => $test->scopeCodes($plan->anchorIds),
        'visible' => $test->scopeCodes($visible),
    ];
}

/**
 * Insert an extra live node with an explicit path and register it in the fixture id map.
 */
function insertPathNode(object $test, string $code, string $path, ?string $parentCode = null): int
{
    $id = (int) DB::table('scope_fixture_nodes')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'parent_id' => $parentCode === null ? null : $test->nodeId($parentCode),
        'code' => $code,
        'name' => Str::title(str_replace('_', ' ', $code)),
        'path' => $path,
        'deleted_at' => null,
    ]);

    (fn () => $this->scopeNodeIds[$code] = $id)->call($test);

    return $id;
}

dataset('planner cases', [
    'descendants of MKT_HOTTOY' => [[['MKT_HOTTOY', Expansion::Descendants]], [], ['MKT_HOTTOY'], ['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']],
    'branch of HOTTOY_A' => [[['HOTTOY_A', Expansion::Branch]], [], ['MKT_HOTTOY'], ['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']],
    'branch of MKT_HOTTOY' => [[['MKT_HOTTOY', Expansion::Branch]], [], ['MKT_HOTTOY'], ['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']],
    'branch of root MKT' => [[['MKT', Expansion::Branch]], ['MKT'], [], ['MKT']],
    'self of HOTTOY_A' => [[['HOTTOY_A', Expansion::SelfOnly]], ['HOTTOY_A'], [], ['HOTTOY_A']],
    'descendants of root MKT' => [[['MKT', Expansion::Descendants]], [], ['MKT'], ['HOBBY_A', 'HOTTOY_A', 'HOTTOY_B', 'MKT', 'MKT_HOBBY', 'MKT_HOTTOY']],
    'two self holdings' => [[['HOTTOY_A', Expansion::SelfOnly], ['TRADE_X', Expansion::SelfOnly]], ['HOTTOY_A', 'TRADE_X'], [], ['HOTTOY_A', 'TRADE_X']],
    'branch of HOBBY_A' => [[['HOBBY_A', Expansion::Branch]], [], ['MKT_HOBBY'], ['HOBBY_A', 'MKT_HOBBY']],
    'branch of TRADE_X' => [[['TRADE_X', Expansion::Branch]], [], ['TRADE_X'], ['TRADE_X']],
]);

it('matches the planner expectations on the path variant', function (array $holdings, array $self, array $anchors, array $visible): void {
    $r = pathScopePlan($this, $holdings, $this->hierarchy);

    expect($r['self'])->toBe($self)
        ->and($r['anchors'])->toBe($anchors)
        ->and($r['visible'])->toBe($visible);

    if ($anchors === ['MKT_HOTTOY']) {
        expect($r['visible'])->not->toContain('MKT', 'MKT_HOBBY', 'HOBBY_A', 'TRADE', 'TRADE_X');
    }
})->with('planner cases');

it('anchors on the path variant equal those of the closure variant for every case', function (): void {
    $closure = $this->scopeClosureHierarchy();

    foreach (array_keys(self::scopeTreeShape()) as $code) {
        foreach (Expansion::cases() as $expansion) {
            $label = "{$code} held with {$expansion->value}";
            $viaClosure = pathScopePlan($this, [[$code, $expansion]], $closure);
            $viaPath = pathScopePlan($this, [[$code, $expansion]], $this->hierarchy);

            expect($viaPath['self'])->toBe($viaClosure['self'], $label)
                ->and($viaPath['anchors'])->toBe($viaClosure['anchors'], $label)
                ->and($viaPath['visible'])->toBe($viaClosure['visible'], $label);
        }
    }
});

it('resolves parents from the path and never exposes the root through a branch', function (): void {
    expect($this->hierarchy->parentOf($this->nodeId('HOTTOY_A')))->toBe($this->nodeId('MKT_HOTTOY'))
        ->and($this->hierarchy->parentOf($this->nodeId('MKT_HOTTOY')))->toBe($this->nodeId('MKT'))
        ->and($this->hierarchy->parentOf($this->nodeId('MKT')))->toBeNull()
        ->and($this->hierarchy->parentOf(999999))->toBeNull();

    foreach (['HOTTOY_A', 'MKT_HOTTOY', 'HOBBY_A', 'MKT_HOBBY'] as $code) {
        $r = pathScopePlan($this, [[$code, Expansion::Branch]], $this->hierarchy);

        expect($r['anchors'])->not->toContain('MKT')
            ->and($r['visible'])->not->toContain('MKT', 'TRADE', 'TRADE_X');
    }
});

it('treats a malformed path as missing hierarchy data', function (string $path, Expansion $expansion): void {
    DB::table('scope_fixture_nodes')->where('id', $this->nodeId('MKT_HOTTOY'))->update(['path' => $path]);

    expect($this->hierarchy->exists($this->nodeId('MKT_HOTTOY')))->toBeTrue()
        ->and($this->hierarchy->hasHierarchyData($this->nodeId('MKT_HOTTOY')))->toBeFalse()
        ->and($this->hierarchy->parentOf($this->nodeId('MKT_HOTTOY')))->toBeNull()
        ->and($this->hierarchy->trail($this->nodeId('MKT_HOTTOY')))->toBe(['Mkt Hottoy']);

    $r = pathScopePlan($this, [['MKT_HOTTOY', $expansion]], $this->hierarchy);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['MKT_HOTTOY'])
        ->and($r['visible'])->toBe(['MKT_HOTTOY']);
})->with([
    'empty' => '',
    'no separators' => 'MKT_HOTTOY',
    'only separators' => '//',
    'missing trailing separator' => '/MKT/MKT_HOTTOY',
    'empty segment' => '/MKT//MKT_HOTTOY/',
    'last segment is not the node code' => '/MKT/MKT_HOBBY/',
    'unknown code segment' => '/NOPE/MKT_HOTTOY/',
    'segment not at its own path' => '/MKT_HOBBY/MKT_HOTTOY/',
])->with([Expansion::Descendants, Expansion::Branch]);

it('breaks the chain below a node whose path moved elsewhere', function (): void {
    DB::table('scope_fixture_nodes')->where('id', $this->nodeId('MKT_HOTTOY'))->update(['path' => '/TRADE/MKT_HOTTOY/']);

    // HOTTOY_A's chain names MKT_HOTTOY at a path it no longer has, so HOTTOY_A itself is malformed too.
    $r = pathScopePlan($this, [['HOTTOY_A', Expansion::Branch]], $this->hierarchy);

    expect($r['anchors'])->toBe([])
        ->and($r['self'])->toBe(['HOTTOY_A'])
        ->and($r['visible'])->toBe(['HOTTOY_A']);
});

it('escapes LIKE wildcards in the anchor path', function (): void {
    insertPathNode($this, 'HOT_TOY', '/HOT_TOY/');
    insertPathNode($this, 'HOTXTOY', '/HOTXTOY/');
    insertPathNode($this, 'HOTXTOY_C', '/HOTXTOY/HOTXTOY_C/', 'HOTXTOY');
    insertPathNode($this, 'PCT%', '/PCT%/');
    insertPathNode($this, 'PCTX', '/PCTX/');
    insertPathNode($this, 'BANG!', '/BANG!/');
    insertPathNode($this, 'BANG!_C', '/BANG!/BANG!_C/', 'BANG!');

    expect(pathScopePlan($this, [['HOT_TOY', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['HOT_TOY'])
        ->and(pathScopePlan($this, [['PCT%', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['PCT%'])
        ->and(pathScopePlan($this, [['BANG!', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['BANG!', 'BANG!_C'])
        ->and(pathScopePlan($this, [['HOTXTOY', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['HOTXTOY', 'HOTXTOY_C']);
});

it('ignores soft-deleted and unknown nodes', function (Expansion $expansion): void {
    $this->softDelete('HOBBY_A');

    $r = pathScopePlan($this, [['HOBBY_A', $expansion], [999999, $expansion]], $this->hierarchy);

    expect($r['self'])->toBe([])
        ->and($r['anchors'])->toBe([])
        ->and($r['visible'])->toBe([]);
})->with([Expansion::SelfOnly, Expansion::Descendants, Expansion::Branch]);

it('hides soft-deleted nodes from subtrees, chains, lookups, labels and search', function (): void {
    $this->softDelete('HOTTOY_B');

    $ids = $this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT_HOTTOY')])->pluck('node_id')->map(fn ($id): int => (int) $id)->all();

    expect($this->scopeCodes($ids))->toBe(['HOTTOY_A', 'MKT_HOTTOY'])
        ->and($this->hierarchy->idForKey('HOTTOY_B'))->toBeNull()
        ->and($this->hierarchy->keyFor($this->nodeId('HOTTOY_B')))->toBeNull()
        ->and($this->hierarchy->exists($this->nodeId('HOTTOY_B')))->toBeFalse()
        ->and($this->hierarchy->hasHierarchyData($this->nodeId('HOTTOY_B')))->toBeFalse()
        ->and($this->hierarchy->parentOf($this->nodeId('HOTTOY_B')))->toBeNull()
        ->and($this->hierarchy->labels([$this->nodeId('HOTTOY_B')]))->toBe([])
        ->and($this->hierarchy->trail($this->nodeId('HOTTOY_B')))->toBe([])
        ->and(array_column($this->hierarchy->search('hottoy'), 'key'))->toBe(['HOTTOY_A', 'MKT_HOTTOY']);

    // A soft-deleted ancestor breaks the chain of every node below it.
    $this->softDelete('MKT_HOTTOY');

    expect($this->hierarchy->hasHierarchyData($this->nodeId('HOTTOY_A')))->toBeFalse()
        ->and($this->hierarchy->parentOf($this->nodeId('HOTTOY_A')))->toBeNull()
        ->and($this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT_HOTTOY')])->get()->all())->toBe([]);
});

it('maps keys, labels, trails and search hits', function (): void {
    $a = $this->nodeId('HOTTOY_A');
    $b = $this->nodeId('HOTTOY_B');

    expect($this->hierarchy->idForKey('HOTTOY_A'))->toBe($a)
        ->and($this->hierarchy->keyFor($a))->toBe('HOTTOY_A')
        ->and($this->hierarchy->idForKey('NOPE'))->toBeNull()
        ->and($this->hierarchy->keyFor(999999))->toBeNull()
        ->and($this->hierarchy->exists(999999))->toBeFalse()
        ->and($this->hierarchy->labels([$b, $a, 999999]))->toBe([$a => 'Hottoy A', $b => 'Hottoy B'])
        ->and($this->hierarchy->labels([]))->toBe([])
        ->and($this->hierarchy->trail($a))->toBe(['Mkt', 'Mkt Hottoy', 'Hottoy A'])
        ->and($this->hierarchy->trail($this->nodeId('MKT')))->toBe(['Mkt'])
        ->and($this->hierarchy->trail(999999))->toBe([])
        ->and(array_column($this->hierarchy->search('hottoy'), 'key'))->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY'])
        ->and($this->hierarchy->search('hottoy', 1))->toHaveCount(1)
        ->and($this->hierarchy->search('%'))->toBe([]);
});

it('returns no rows for empty or malformed anchors', function (): void {
    DB::table('scope_fixture_nodes')->where('id', $this->nodeId('MKT_HOBBY'))->update(['path' => '/']);

    expect($this->hierarchy->subtreeIdsQuery([])->get()->all())->toBe([])
        ->and($this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT_HOBBY'), 999999])->get()->all())->toBe([]);
});

it('never lets a subtree reach a sibling path that differs only by case', function (): void {
    insertPathNode($this, 'HOT_TOY', '/ROOT_UP/HOT_TOY/');
    insertPathNode($this, 'HOT_TOY_C', '/ROOT_UP/HOT_TOY/HOT_TOY_C/', 'HOT_TOY');
    insertPathNode($this, 'hot_toy', '/ROOT_UP/hot_toy/');
    insertPathNode($this, 'hot_toy_c', '/ROOT_UP/hot_toy/hot_toy_c/', 'hot_toy');
    insertPathNode($this, 'ROOT_UP', '/ROOT_UP/');

    $subtree = fn (string $code): array => $this->scopeCodes(
        $this->hierarchy->subtreeIdsQuery([$this->nodeId($code)])->pluck('node_id')->map(fn ($id): int => (int) $id)->all()
    );

    expect($subtree('HOT_TOY'))->toBe(['HOT_TOY', 'HOT_TOY_C'])
        ->and($subtree('hot_toy'))->toBe(['hot_toy', 'hot_toy_c']);
});

it('keeps case-different sibling subtrees apart through the planner', function (): void {
    insertPathNode($this, 'ROOT_UP', '/ROOT_UP/');
    insertPathNode($this, 'HOT_TOY', '/ROOT_UP/HOT_TOY/', 'ROOT_UP');
    insertPathNode($this, 'HOT_TOY_C', '/ROOT_UP/HOT_TOY/HOT_TOY_C/', 'HOT_TOY');
    insertPathNode($this, 'hot_toy', '/ROOT_UP/hot_toy/', 'ROOT_UP');
    insertPathNode($this, 'hot_toy_c', '/ROOT_UP/hot_toy/hot_toy_c/', 'hot_toy');

    expect(pathScopePlan($this, [['HOT_TOY', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['HOT_TOY', 'HOT_TOY_C'])
        ->and(pathScopePlan($this, [['hot_toy', Expansion::Branch]], $this->hierarchy)['visible'])->toBe(['hot_toy', 'hot_toy_c'])
        ->and(pathScopePlan($this, [['hot_toy', Expansion::Descendants]], $this->hierarchy)['visible'])->toBe(['hot_toy', 'hot_toy_c']);
});

it('refuses to build a subtree query on an unsupported database driver', function (): void {
    $id = insertPathNode($this, 'ROOT_UP', '/ROOT_UP/');
    $hierarchy = new class('scope_fixture_nodes') extends PathHierarchy {
        protected function driverName(): string
        {
            return 'sqlsrv';
        }
    };

    expect(fn () => $hierarchy->subtreeIdsQuery([$id]))->toThrow(\RuntimeException::class);
});
