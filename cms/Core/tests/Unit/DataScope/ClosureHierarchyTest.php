<?php

declare(strict_types=1);

use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;

uses(\Cms\Core\Tests\TestCase::class, ScopeTreeFixture::class);

beforeEach(function (): void {
    $this->createScopeTreeFixture();
    $this->hierarchy = $this->scopeClosureHierarchy();
});

it('maps keys and ids both ways', function (): void {
    $id = $this->nodeId('HOTTOY_A');

    expect($this->hierarchy->idForKey('HOTTOY_A'))->toBe($id)
        ->and($this->hierarchy->keyFor($id))->toBe('HOTTOY_A')
        ->and($this->hierarchy->idForKey('NOPE'))->toBeNull()
        ->and($this->hierarchy->keyFor(999999))->toBeNull();
});

it('reports existence, parents and hierarchy data', function (): void {
    expect($this->hierarchy->exists($this->nodeId('MKT')))->toBeTrue()
        ->and($this->hierarchy->exists(999999))->toBeFalse()
        ->and($this->hierarchy->parentOf($this->nodeId('HOTTOY_A')))->toBe($this->nodeId('MKT_HOTTOY'))
        ->and($this->hierarchy->parentOf($this->nodeId('MKT')))->toBeNull()
        ->and($this->hierarchy->parentOf(999999))->toBeNull()
        ->and($this->hierarchy->hasHierarchyData($this->nodeId('HOTTOY_A')))->toBeTrue();

    $this->deleteClosureRows('HOTTOY_A');

    expect($this->hierarchy->hasHierarchyData($this->nodeId('HOTTOY_A')))->toBeFalse();
});

it('returns labels, trails and search hits', function (): void {
    $a = $this->nodeId('HOTTOY_A');
    $b = $this->nodeId('HOTTOY_B');

    expect($this->hierarchy->labels([$b, $a, 999999]))->toBe([$a => 'Hottoy A', $b => 'Hottoy B'])
        ->and($this->hierarchy->labels([]))->toBe([])
        ->and($this->hierarchy->trail($a))->toBe(['Mkt', 'Mkt Hottoy', 'Hottoy A'])
        ->and($this->hierarchy->trail(999999))->toBe([]);

    $hits = $this->hierarchy->search('hottoy');

    expect(array_column($hits, 'key'))->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY'])
        ->and($hits[0])->toBe(['id' => $a, 'key' => 'HOTTOY_A', 'label' => 'Hottoy A'])
        ->and($this->hierarchy->search('hottoy', 1))->toHaveCount(1);
});

it('selects anchors and their descendants as node_id', function (): void {
    $ids = $this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT_HOBBY'), $this->nodeId('TRADE_X')])
        ->pluck('node_id')->map(fn ($id): int => (int) $id)->all();

    expect($this->scopeCodes($ids))->toBe(['HOBBY_A', 'MKT_HOBBY', 'TRADE_X'])
        ->and($this->hierarchy->subtreeIdsQuery([])->get()->all())->toBe([]);
});

it('hides soft-deleted nodes everywhere', function (): void {
    $this->softDelete('HOBBY_A');
    $id = $this->nodeId('HOBBY_A');

    $subtree = $this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT_HOBBY')])
        ->pluck('node_id')->map(fn ($v): int => (int) $v)->all();

    expect($this->hierarchy->exists($id))->toBeFalse()
        ->and($this->hierarchy->idForKey('HOBBY_A'))->toBeNull()
        ->and($this->hierarchy->keyFor($id))->toBeNull()
        ->and($this->hierarchy->labels([$id]))->toBe([])
        ->and($this->hierarchy->trail($id))->toBe([])
        ->and($this->hierarchy->search('hobby'))->toHaveCount(1)
        ->and($this->scopeCodes($subtree))->toBe(['MKT_HOBBY']);
});
