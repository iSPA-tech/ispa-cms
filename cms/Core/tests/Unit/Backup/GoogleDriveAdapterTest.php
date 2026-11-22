<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Cms\Core\Services\Backup\Destinations\GoogleDriveAdapter;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;

/*
 * Google Drive backup destination (hand-written Flysystem adapter on the Http client).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const GDA_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GDA_API = 'https://www.googleapis.com/drive/v3/files';
const GDA_UPLOAD_INIT = 'https://www.googleapis.com/upload/drive/v3/files';
const GDA_SESSION = 'https://upload.example.test/session/abc';

/**
 * Create a connected Google Drive destination with generated fake credentials.
 */
function gdaDestination(): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'drive ' . bin2hex(random_bytes(3)),
        'driver' => 'gdrive',
        'config' => ['client_id' => 'cid-' . bin2hex(random_bytes(4))],
        'credentials' => ['client_secret' => 'cs-' . bin2hex(random_bytes(6)), 'refresh_token' => 'rt-' . bin2hex(random_bytes(6))],
    ]);
}

/**
 * Build an adapter with a small chunk size.
 */
function gdaAdapter(BackupDestination $destination, int $chunk = 4): GoogleDriveAdapter
{
    return new GoogleDriveAdapter($destination, new OAuthConnector(), $chunk);
}

/**
 * Open a memory stream holding the contents.
 *
 * @return resource
 */
function gdaStream(string $contents)
{
    $h = fopen('php://memory', 'w+b');
    fwrite($h, $contents);
    rewind($h);

    return $h;
}

/**
 * Fake the Drive API: folders are looked up in $folders (name => id) and created on demand, files in $files.
 *
 * @param array<int, HttpRequest> $log
 * @param array<int, mixed> $putResponses Responses (Http::response results) for upload PUTs, in order
 * @param array<string, string> $files Existing file names (name => id)
 */
function gdaFake(array &$log, array $putResponses = [], array $files = [], array $tokens = ['at-1']): void
{
    $put = $putResponses;
    $tokenSeq = Http::sequence();
    foreach ($tokens as $t) {
        $tokenSeq->push(['access_token' => $t, 'expires_in' => 3600]);
    }
    $created = [];
    Http::fake([
        GDA_TOKEN_URL => $tokenSeq,
        GDA_UPLOAD_INIT . '*' => function (HttpRequest $r) use (&$log) {
            $log[] = $r;

            return Http::response('', 200, ['Location' => GDA_SESSION]);
        },
        GDA_SESSION => function (HttpRequest $r) use (&$log, &$put) {
            $log[] = $r;

            return array_shift($put) ?? Http::response(['id' => 'file-1'], 200);
        },
        GDA_API . '*' => function (HttpRequest $r) use (&$log, &$created, $files) {
            $log[] = $r;
            if ($r->method() === 'POST') {
                $name = $r->data()['name'];
                $created[$name] = 'fid-' . $name;

                return Http::response(['id' => 'fid-' . $name]);
            }
            if ($r->method() === 'DELETE') {
                return Http::response('', 204);
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $query = (string) ($q['q'] ?? '');
            if (str_contains($query, 'application/vnd.google-apps.folder') && preg_match("/^name='((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
                $name = stripslashes($m[1]);

                return Http::response(['files' => isset($created[$name]) ? [['id' => $created[$name]]] : []]);
            }
            if (preg_match("/^name='((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
                $name = stripslashes($m[1]);

                return Http::response(['files' => isset($files[$name]) ? [['id' => $files[$name], 'name' => $name, 'size' => '42', 'modifiedTime' => '2026-11-06T10:00:00.000Z']] : []]);
            }

            return Http::response(['files' => []]);
        },
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

it('uploads in ordered chunks and sends the final total', function (): void {
    $log = [];
    gdaFake($log, [
        Http::response('', 308, ['Range' => 'bytes=0-3']),
        Http::response('', 308, ['Range' => 'bytes=0-7']),
        Http::response(['id' => 'f'], 200),
    ]);
    gdaAdapter(gdaDestination())->writeStream('app/site/2026-11-06/a.sql.gz', gdaStream('0123456789'), new Config());

    $puts = array_values(array_filter($log, fn (HttpRequest $r) => $r->method() === 'PUT'));
    expect(array_map(fn ($r) => $r->header('Content-Range')[0], $puts))->toBe(['bytes 0-3/*', 'bytes 4-7/*', 'bytes 8-9/10'])
        ->and(array_map(fn ($r) => $r->body(), $puts))->toBe(['0123', '4567', '89']);
});

it('resumes from the Range header after a 308', function (): void {
    $log = [];
    gdaFake($log, [
        Http::response('', 308, ['Range' => 'bytes=0-1']),
        Http::response('', 308, ['Range' => 'bytes=0-3']),
        Http::response('', 308, ['Range' => 'bytes=0-7']),
        Http::response(['id' => 'f'], 200),
    ]);
    gdaAdapter(gdaDestination())->writeStream('x/a.txt', gdaStream('0123456789'), new Config());

    $puts = array_values(array_filter($log, fn (HttpRequest $r) => $r->method() === 'PUT'));
    expect(array_map(fn ($r) => $r->header('Content-Range')[0], $puts))->toBe(['bytes 0-3/*', 'bytes 2-3/*', 'bytes 4-7/*', 'bytes 8-9/10'])
        ->and($puts[1]->body())->toBe('23');
});

it('creates each folder once across two writes', function (): void {
    $log = [];
    gdaFake($log);
    $adapter = gdaAdapter(gdaDestination(), 1024);
    $adapter->write('app/site/2026/a.txt', 'a', new Config());
    $adapter->write('app/site/2026/b.txt', 'b', new Config());

    $folderPosts = array_filter($log, fn (HttpRequest $r) => $r->method() === 'POST' && str_starts_with($r->url(), GDA_API));
    // iSPA backups, app, site, 2026
    expect(count($folderPosts))->toBe(4);
});

it('roots at iSPA backups and lets the object key supply the site folder once', function (): void {
    $log = [];
    gdaFake($log, [], [], ['at-1']);
    gdaAdapter(gdaDestination(), 1024)->write('backups/acme/2026-11-06/db-x.sql.gz', 'x', new Config());

    $names = collect($log)
        ->filter(fn (HttpRequest $r) => $r->method() === 'POST' && str_starts_with($r->url(), GDA_API))
        ->map(fn (HttpRequest $r) => $r->data()['name'])
        ->values()
        ->all();
    expect($names)->toBe(['iSPA backups', 'backups', 'acme', '2026-11-06'])
        ->and(array_count_values($names)['acme'])->toBe(1);
});

it('reports an existing name and deletes by name', function (): void {
    $log = [];
    gdaFake($log, [], ['a.txt' => 'file-9']);
    $adapter = gdaAdapter(gdaDestination());
    $adapter->write('d/seed.txt', 's', new Config());

    expect($adapter->fileExists('d/a.txt'))->toBeTrue()
        ->and($adapter->fileExists('d/zzz.txt'))->toBeFalse();
    $adapter->delete('d/a.txt');
    expect(collect($log)->contains(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/file-9')))->toBeTrue();
});

it('refreshes the token once on a 401 and then succeeds', function (): void {
    $auth = [];
    Http::fake([
        GDA_TOKEN_URL => Http::sequence()->push(['access_token' => 'at-old', 'expires_in' => 3600])->push(['access_token' => 'at-new', 'expires_in' => 3600]),
        GDA_API . '*' => function (HttpRequest $r) use (&$auth) {
            $auth[] = $r->header('Authorization')[0];

            return count($auth) === 1 ? Http::response('', 401) : Http::response(['files' => []]);
        },
    ]);

    expect(gdaAdapter(gdaDestination())->fileExists('a.txt'))->toBeFalse()
        ->and($auth)->toBe(['Bearer at-old', 'Bearer at-new'])
        ->and(count(Http::recorded(fn (HttpRequest $r) => $r->url() === GDA_TOKEN_URL)))->toBe(2);
});

it('fails with BACKUP_OAUTH_RECONNECT after a second 401 without leaking tokens', function (): void {
    $destination = gdaDestination();
    Http::fake([
        GDA_TOKEN_URL => Http::sequence()->push(['access_token' => 'at-LEAK-1', 'expires_in' => 3600])->push(['access_token' => 'at-LEAK-2', 'expires_in' => 3600]),
        GDA_API . '*' => Http::response('', 401),
    ]);

    try {
        gdaAdapter($destination)->fileExists('a.txt');
        $this->fail('expected exception');
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('BACKUP_OAUTH_RECONNECT')
            ->and($e->getMessage())->not->toContain('at-LEAK');
    }
    expect($destination->fresh()->last_error_code)->toBe('BACKUP_OAUTH_RECONNECT');
});

it('refuses unsupported operations', function (): void {
    expect(fn () => gdaAdapter(gdaDestination())->copy('a', 'b', new Config()))->toThrow(UnableToCopyFile::class);
});

it('registers the gdrive driver and builds its disk without network', function (): void {
    $registry = new DestinationRegistry();
    expect($registry->has('gdrive'))->toBeTrue();

    $destination = gdaDestination();
    expect($registry->get('gdrive')->validate([], []))->toBe(['BACKUP_OAUTH_CLIENT_MISSING'])
        ->and($registry->get('gdrive')->diskConfig($destination)['destination_uuid'])->toBe($destination->uuid);

    Http::fake();
    DestinationDisk::for($destination);
    Http::assertNothingSent();
});
