<?php

declare(strict_types=1);

use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;

uses(\Cms\Core\Tests\TestCase::class, ScopeTreeFixture::class);

beforeEach(function (): void {
    $this->createScopeTreeFixture();
    $this->hierarchy = $this->scopeFlatHierarchy();
});

it('maps keys and ids and has no parents', function (): void {
    $id = $this->nodeId('HOTTOY_A');

    expect($this->hierarchy->idForKey('HOTTOY_A'))->toBe($id)
        ->and($this->hierarchy->keyFor($id))->toBe('HOTTOY_A')
        ->and($this->hierarchy->idForKey('NOPE'))->toBeNull()
        ->and($this->hierarchy->exists($id))->toBeTrue()
        ->and($this->hierarchy->exists(999999))->toBeFalse()
        ->and($this->hierarchy->parentOf($id))->toBeNull()
        ->and($this->hierarchy->hasHierarchyData($id))->toBeTrue()
        ->and($this->hierarchy->hasHierarchyData(999999))->toBeFalse();
});

it('returns labels, a one-step trail and search hits', function (): void {
    $id = $this->nodeId('HOTTOY_A');

    expect($this->hierarchy->labels([$id, 999999]))->toBe([$id => 'Hottoy A'])
        ->and($this->hierarchy->trail($id))->toBe(['Hottoy A'])
        ->and($this->hierarchy->trail(999999))->toBe([])
        ->and(array_column($this->hierarchy->search('trade'), 'key'))->toBe(['TRADE', 'TRADE_X']);
});

it('limits the subtree to the anchors themselves', function (): void {
    $ids = $this->hierarchy->subtreeIdsQuery([$this->nodeId('MKT'), 999999])
        ->pluck('node_id')->map(fn ($v): int => (int) $v)->all();

    expect($this->scopeCodes($ids))->toBe(['MKT'])
        ->and($this->hierarchy->subtreeIdsQuery([])->get()->all())->toBe([]);
});

it('hides soft-deleted rows', function (): void {
    $this->softDelete('TRADE_X');
    $id = $this->nodeId('TRADE_X');

    expect($this->hierarchy->exists($id))->toBeFalse()
        ->and($this->hierarchy->keyFor($id))->toBeNull()
        ->and($this->hierarchy->labels([$id]))->toBe([])
        ->and($this->hierarchy->subtreeIdsQuery([$id])->get()->all())->toBe([])
        ->and(array_column($this->hierarchy->search('trade'), 'key'))->toBe(['TRADE']);
});
