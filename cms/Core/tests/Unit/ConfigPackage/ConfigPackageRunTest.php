<?php

use Cms\Core\Models\ConfigPackageRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function makeRun(array $attrs = []): ConfigPackageRun
{
    return ConfigPackageRun::query()->create($attrs + [
        'kind' => ConfigPackageRun::KIND_EXPORT,
        'status' => ConfigPackageRun::STATUS_QUEUED,
        'sections' => ['settings', 'menus'],
    ]);
}

test('payload round trip keeps unicode', function () {
    $package = ['title' => 'Cài đặt hệ thống', 'emoji' => "ok \u{1F680}", 'url' => 'a/b'];
    $run = makeRun();
    $run->setPayload($package)->save();

    expect($run->fresh()->payload())->toBe($package)->and($run->fresh()->hasPayload())->toBeTrue();
});

test('stored payload is base64 text, not raw json', function () {
    $run = makeRun();
    $run->setPayload(['a' => 'Cài đặt'])->save();

    $stored = (string) ConfigPackageRun::query()->whereKey($run->id)->value('payload');
    expect($stored)->not->toContain('{')
        ->and(base64_decode($stored, true))->not->toBeFalse();
});

test('a roughly 3 MB payload survives save and fresh', function () {
    $package = ['rows' => array_map(static fn () => bin2hex(random_bytes(512)), range(1, 3000))];
    $run = makeRun();
    $run->setPayload($package)->save();

    expect($run->fresh()->payload())->toBe($package);
});

test('serialization never contains the payload', function () {
    $run = makeRun();
    $run->setPayload(['secret' => 'x'])->save();
    $fresh = $run->fresh();

    expect($fresh->toArray())->not->toHaveKey('payload')
        ->and($fresh->toJson())->not->toContain('payload')
        ->and($fresh->getHidden())->toContain('payload');
});

test('a corrupt payload throws a generic RuntimeException', function () {
    $run = makeRun(['payload' => 'not-valid-base64-or-gzip!!']);
    expect(fn () => $run->fresh()->payload())->toThrow(RuntimeException::class, 'Config package payload is corrupt.');

    $run2 = makeRun(['payload' => base64_encode('plain text, not gzip')]);
    expect(fn () => $run2->fresh()->payload())->toThrow(RuntimeException::class);
});

test('an empty payload reads as an empty array and sections are cast', function () {
    $run = makeRun();
    expect($run->fresh()->payload())->toBe([])->and($run->fresh()->hasPayload())->toBeFalse()
        ->and($run->fresh()->sections)->toBe(['settings', 'menus'])
        ->and($run->fresh()->uuid)->not->toBeEmpty();
});
