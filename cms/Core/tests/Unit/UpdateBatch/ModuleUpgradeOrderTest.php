<?php

declare(strict_types=1);

use Cms\Core\Services\UpdateBatch\ModuleUpgradeOrder;

uses(\Cms\Core\Tests\TestCase::class);

// Upgrade-all spec 2026-11-26 decision 3: modules run in requirement order, ties by name; requirement cycles are
// left out ("làm riêng").

it('upgrades Account before SocialAuth', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'SocialAuth' => ['version' => '2026.11.06', 'requires' => ['Account' => '>=2026.11.05']],
        'Account' => ['version' => '2026.11.05', 'requires' => []],
    ], []);

    expect($order)->toBe(['ordered' => ['Account', 'SocialAuth'], 'cyclic' => []]);
});

it('keeps independent modules in name order', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'Webmail' => ['version' => '2026.11.03', 'requires' => []],
        'Education' => ['version' => '2026.11.02', 'requires' => []],
        'Account' => ['version' => '2026.11.05', 'requires' => []],
    ], []);

    expect($order['ordered'])->toBe(['Account', 'Education', 'Webmail']);
});

it('reads a lower-case requirement name as the module', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'SocialAuth' => ['version' => '2026.11.06', 'requires' => ['account' => '*']],
        'Account' => ['version' => '2026.11.05', 'requires' => []],
    ], []);

    expect($order['ordered'])->toBe(['Account', 'SocialAuth']);
});

it('moves a dependent first when its installed version pins the other module', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'Account' => ['version' => '2026.12.1', 'requires' => []],
        'Forum' => ['version' => '2026.12.2', 'requires' => ['Account' => '<2027.1.0']],
    ], [
        'Forum' => ['Account' => '<2026.12.0'],
    ], ['Account' => '2026.11.05', 'Forum' => '2026.11.01']);

    expect($order['ordered'])->toBe(['Forum', 'Account']);
});

it('does not order by a requirement the installed version already meets', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'Zeta' => ['version' => '2026.12.01', 'requires' => []],
        'Beta' => ['version' => '2026.12.01', 'requires' => ['Zeta' => '>=2026.10.01']],
    ], [], ['Zeta' => '2026.11.01', 'Beta' => '2026.11.01']);

    expect($order['ordered'])->toBe(['Beta', 'Zeta']);
});

it('reports modules that require each other as a cycle', function (): void {
    $order = (new ModuleUpgradeOrder())->sort([
        'Alpha' => ['version' => '2026.11.01', 'requires' => ['Beta' => '*']],
        'Beta' => ['version' => '2026.11.01', 'requires' => ['Alpha' => '*']],
        'Gamma' => ['version' => '2026.11.01', 'requires' => []],
    ], []);

    expect($order['ordered'])->toBe(['Gamma'])
        ->and($order['cyclic'])->toEqualCanonicalizing(['Alpha', 'Beta']);
});
