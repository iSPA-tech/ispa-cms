<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use App\Support\Modules\RuntimePrecedence;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;

// Before Core moved to CalVer (2026-11-22) some sites were numbered 2026.16.2, 2026.16.10: a "month" above 12. That code
// is OLDER than every CalVer release, so such a number sorts below every valid CalVer version and never meets a lower
// CalVer bound. An upper bound written with a month above 12 (`<2026.13.00`) stays a plain numeric bound.

it('treats a version whose month is above 12 as legacy', function (string $version, bool $legacy): void {
    expect(ModuleVersionConstraint::isLegacy($version))->toBe($legacy);
})->with([
    ['2026.16.2', true],
    ['2026.16.10', true],
    ['2026.13.00', true],
    ['2026.11.07', false],
    ['2026.12.01', false],
    ['2026.1.0', false],
    ['1.0.0', false],
]);

it('never lets a legacy Core meet a CalVer lower bound', function (string $version, string $constraint, bool $expected): void {
    expect(ModuleVersionConstraint::satisfies($version, $constraint))->toBe($expected)
        ->and(RuntimePrecedence::satisfies($version, $constraint))->toBe($expected);
})->with([
    'legacy 2026.16.2 below 2026.11.06' => ['2026.16.2', '>=2026.11.06,<2027.00.00', false],
    'legacy 2026.16.10 below 2026.11.06' => ['2026.16.10', '>=2026.11.06,<2027.00.00', false],
    'legacy 2026.16.2 below 2026.11.00' => ['2026.16.2', '>=2026.11.00,<2027.00.00', false],
    'CalVer 2026.11.07 passes' => ['2026.11.07', '>=2026.11.06,<2027.00.00', true],
    'CalVer 2026.11.06 passes' => ['2026.11.06', '>=2026.11.06,<2027.00.00', true],
    'CalVer 2026.11.05 fails' => ['2026.11.05', '>=2026.11.06,<2027.00.00', false],
    'guard bound keeps CalVer' => ['2026.12.01', '>=2026.11.07,<2026.13.00', true],
    'guard bound refuses legacy' => ['2026.16.2', '>=2026.11.07,<2026.13.00', false],
    'legacy under a plain upper bound only' => ['2026.16.2', '<2027.00.00', true],
    'any version for star' => ['2026.16.2', '*', true],
]);

it('orders legacy numbers below every CalVer version', function (string $a, string $b, int $sign): void {
    expect(ModuleVersionConstraint::compare($a, $b) <=> 0)->toBe($sign);
})->with([
    ['2026.12.01', '2026.11.07', 1],
    ['2026.16.2', '2026.11.00', -1],
    ['2026.16.10', '2026.01.01', -1],
    ['2026.11.07', '2026.16.10', 1],
    ['2026.16.10', '2026.16.2', 1],
    ['2026.11.04', '2026.11.3', 1],
    ['2026.11.07', '2026.11.07', 0],
]);

it('offers a CalVer upgrade to a module installed with a legacy number', function (): void {
    $offer = (new ReflectionMethod(ModuleCatalog::class, 'upgradeOffer'))->invoke(new ModuleCatalog(), [
        'name' => 'Probe',
        'versions' => [['version' => '2026.11.07', 'revoked' => false], ['version' => '2026.11.01', 'revoked' => false]],
    ], ['installed' => 'runtime', 'installed_version' => '2026.16.2']);

    expect($offer['upgrade_version'])->toBe('2026.11.07');
});
