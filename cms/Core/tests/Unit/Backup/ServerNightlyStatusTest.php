<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\ServerNightlyStatus;
use Illuminate\Support\Carbon;

uses(Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    $this->nightlyDir = sys_get_temp_dir() . '/server-nightly-' . bin2hex(random_bytes(6));
    mkdir($this->nightlyDir, 0700, true);
    $this->nightlyPath = $this->nightlyDir . '/server-nightly.json';
    Carbon::setTestNow(Carbon::parse('2026-11-06 06:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob($this->nightlyDir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->nightlyDir);
});

/**
 * Writes a status file with sensible defaults, overridden by $override.
 *
 * @param array<string, mixed> $override
 */
function writeNightly(string $path, array $override = []): void
{
    $payload = array_merge([
        'schema' => 1,
        'label' => 'srv-a',
        'date' => '2026-11-06',
        'finished_at' => '2026-11-06T02:10:00Z',
        'objects' => [
            ['key' => 'srv-a/2026-11-06/db/mysql-db_a.sql.zst', 'database' => 'db_a', 'tables_expected' => 3, 'tables_dumped' => 3, 'failed' => false],
        ],
    ], $override);
    file_put_contents($path, json_encode($payload));
}

it('reads a valid file as ok', function () {
    writeNightly($this->nightlyPath);

    $status = (new ServerNightlyStatus($this->nightlyPath))->read();

    expect($status['state'])->toBe('ok')
        ->and($status['label'])->toBe('srv-a')
        ->and($status['date'])->toBe('2026-11-06')
        ->and($status['finished_at'])->toBe('2026-11-06T02:10:00Z')
        ->and($status['error'])->toBeNull()
        ->and($status['objects'])->toBe([
            ['key' => 'srv-a/2026-11-06/db/mysql-db_a.sql.zst', 'database' => 'db_a', 'tables_expected' => 3, 'tables_dumped' => 3, 'failed' => false],
        ]);
});

it('reports failed when any object failed', function () {
    writeNightly($this->nightlyPath, ['objects' => [
        ['key' => 'k1', 'database' => 'a', 'tables_expected' => 3, 'tables_dumped' => 3, 'failed' => false],
        ['key' => 'k2', 'database' => 'b', 'tables_expected' => null, 'tables_dumped' => null, 'failed' => true],
    ]]);

    $status = (new ServerNightlyStatus($this->nightlyPath))->read();

    expect($status['state'])->toBe('failed')
        ->and($status['objects'][1]['tables_expected'])->toBeNull()
        ->and($status['objects'][1]['failed'])->toBeTrue();
});

it('reports stale when finished more than 30 hours ago, even if an object failed', function () {
    writeNightly($this->nightlyPath, [
        'finished_at' => '2026-11-04T23:00:00Z',
        'objects' => [['key' => 'k', 'database' => 'a', 'tables_expected' => 1, 'tables_dumped' => 0, 'failed' => true]],
    ]);

    expect((new ServerNightlyStatus($this->nightlyPath))->read()['state'])->toBe('stale');
});

it('honours a custom stale threshold', function () {
    writeNightly($this->nightlyPath, ['finished_at' => '2026-11-06T02:10:00Z']);

    expect((new ServerNightlyStatus($this->nightlyPath, 2))->read()['state'])->toBe('stale')
        ->and((new ServerNightlyStatus($this->nightlyPath, 48))->read()['state'])->toBe('ok');
});

it('reports invalid for malformed JSON without throwing', function () {
    file_put_contents($this->nightlyPath, '{not json');

    $status = (new ServerNightlyStatus($this->nightlyPath))->read();

    expect($status['state'])->toBe('invalid')
        ->and($status['error'])->toBeString()
        ->and($status['objects'])->toBe([])
        ->and($status['finished_at'])->toBeNull();
});

it('reports invalid for a wrong schema or wrong types', function (array $override) {
    writeNightly($this->nightlyPath, $override);

    expect((new ServerNightlyStatus($this->nightlyPath))->read()['state'])->toBe('invalid');
})->with([
    'schema 2' => [['schema' => 2]],
    'schema string' => [['schema' => '1']],
    'label missing type' => [['label' => 5]],
    'bad finished_at' => [['finished_at' => 'yesterday']],
    'objects not list' => [['objects' => 'x']],
    'object bad failed' => [['objects' => [['key' => 'k', 'database' => 'a', 'tables_expected' => null, 'tables_dumped' => null, 'failed' => 'no']]]],
    'object bad count' => [['objects' => [['key' => 'k', 'database' => 'a', 'tables_expected' => 'x', 'tables_dumped' => null, 'failed' => false]]]],
]);

it('reports unknown when the file is missing', function () {
    $status = (new ServerNightlyStatus($this->nightlyDir . '/missing.json'))->read();

    expect($status['state'])->toBe('unknown')
        ->and($status['objects'])->toBe([])
        ->and($status['error'])->toBeNull();
});

it('ignores unknown fields', function () {
    writeNightly($this->nightlyPath, [
        'extra' => 'x',
        'objects' => [['key' => 'k', 'database' => 'a', 'tables_expected' => 2, 'tables_dumped' => 2, 'failed' => false, 'size' => 99]],
    ]);

    $status = (new ServerNightlyStatus($this->nightlyPath))->read();

    expect($status['state'])->toBe('ok')
        ->and($status['objects'][0])->toBe(['key' => 'k', 'database' => 'a', 'tables_expected' => 2, 'tables_dumped' => 2, 'failed' => false]);
});
