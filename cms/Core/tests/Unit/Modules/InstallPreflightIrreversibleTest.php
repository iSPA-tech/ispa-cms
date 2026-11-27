<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Install\InstallPreflight;

uses(\Cms\Core\Tests\TestCase::class);

// Upgrade-all plan (2026-11-26) Task 1: the planner and the preflight share one test for "this version has
// migrations that can not be rolled back" (hub catalog `irreversible`: false, true, null or a list of names).

it('treats false, null and an empty list as reversible', function (mixed $flag): void {
    expect(InstallPreflight::isIrreversible($flag))->toBeFalse();
})->with([[false], [null], [[]]]);

it('treats true and a list of migrations as irreversible', function (mixed $flag): void {
    expect(InstallPreflight::isIrreversible($flag))->toBeTrue();
})->with([[true], [['2026_01_01_000000_drop_x']]]);
