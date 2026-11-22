<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Backup\RunBackupJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Models\Role;
use Cms\Core\Services\Backup\ServerNightlyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * backup admin API (destinations, test, run, history, status); Webmaster only; secrets write-only.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const BAPI_BASE = 'https://localhost/api/v2/admin/backups';

/**
 * Create an admin user (type ADMIN) with a role of the given level (0 = Webmaster).
 */
function bapiUser(string $name, ?int $roleLevel): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::ADMIN,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' ' . Str::random(4), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/**
 * Generated fake S3 credentials (never real keys).
 *
 * @return array{access_key_id: string, secret_access_key: string}
 */
function bapiFakeS3Credentials(): array
{
    return [
        'access_key_id' => 'AKFAKE' . Str::upper(Str::random(14)),
        'secret_access_key' => 'fake-secret-' . Str::random(32),
    ];
}

/**
 * Every admin backup route with a sample path (uuid placeholders filled).
 *
 * @return array<int, array{0: string, 1: string}>
 */
function bapiRoutes(string $uuid): array
{
    return [
        ['GET', BAPI_BASE . '/status'],
        ['GET', BAPI_BASE . '/destinations'],
        ['POST', BAPI_BASE . '/destinations'],
        ['PUT', BAPI_BASE . '/destinations/' . $uuid],
        ['POST', BAPI_BASE . '/destinations/' . $uuid . '/test'],
        ['DELETE', BAPI_BASE . '/destinations/' . $uuid],
        ['GET', BAPI_BASE],
        ['POST', BAPI_BASE . '/run'],
    ];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'app.url' => 'https://cms.example.test/',
        'core.backup.job_timeout' => 840,
        'core.backup.queue' => 'backups',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 900,
    ]);

    // User id 1 is always a Webmaster (User::isWebmaster), so take it with a filler account first.
    bapiUser('filler', null);
    $this->webmaster = bapiUser('webmaster', 0);
    $this->manager = bapiUser('manager', 50);
    expect($this->webmaster->fresh()->isWebmaster())->toBeTrue()
        ->and($this->manager->fresh()->isWebmaster())->toBeFalse();

    $this->logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logs[] = $event->message . ' ' . json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $this->tempDirs = [];
    $this->tempDir = function (): string {
        $dir = sys_get_temp_dir() . '/bapi-' . Str::lower(Str::random(10));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    };

    $this->asWebmaster = fn () => Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $this->createS3 = function (array $credentials, array $overrides = []) {
        ($this->asWebmaster)();

        return $this->postJson(BAPI_BASE . '/destinations', array_replace([
            'name' => 'R2 offsite',
            'driver' => 's3',
            'config' => ['endpoint' => 'https://fake-account.r2.example.test', 'bucket' => 'fake-bucket', 'prefix' => 'site/app'],
            'credentials' => $credentials,
            'retention_days' => 5,
            'lock_days' => 2,
            'schedule' => 'daily@02:30',
        ], $overrides));
    };
});

afterEach(function (): void {
    foreach ($this->tempDirs ?? [] as $dir) {
        File::deleteDirectory($dir);
    }
});

test('every route requires authentication', function (): void {
    foreach (bapiRoutes((string) Str::uuid()) as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401);
    }
});

test('an admin who is not a Webmaster gets 403 WEBMASTER_ONLY on every route', function (): void {
    Queue::fake();
    $destination = BackupDestination::query()->create(['name' => 'd', 'driver' => 'local', 'config' => ['root' => '/nonexistent-bapi']]);
    Sanctum::actingAs($this->manager->fresh(), ['*']);

    foreach (bapiRoutes((string) $destination->uuid) as [$method, $url]) {
        $this->json($method, $url, ['name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WEBMASTER_ONLY');
    }
    expect(BackupDestination::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a Webmaster creates an S3 destination and the secret never leaks', function (): void {
    $credentials = bapiFakeS3Credentials();
    $response = ($this->createS3)($credentials);

    $response->assertStatus(201)
        ->assertJsonPath('data.driver', 's3')
        ->assertJsonPath('data.credentials_set.access_key_id', true)
        ->assertJsonPath('data.credentials_set.secret_access_key', true)
        ->assertJsonPath('data.retention_days', 5)
        ->assertJsonPath('data.schedule', 'daily@02:30')
        ->assertJsonMissingPath('data.credentials');

    $uuid = (string) $response->json('data.uuid');
    $stored = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    expect($stored->credentials)->toBe($credentials)
        ->and($stored->created_by)->toBe($this->webmaster->id);

    $list = $this->getJson(BAPI_BASE . '/destinations')->assertOk();
    $status = $this->getJson(BAPI_BASE . '/status')->assertOk();

    $audit = DB::table(CoreTableConstant::AUDIT_LOGS)->get();
    expect($audit->pluck('action')->all())->toContain('action.backup-destination-create');
    $createRow = $audit->firstWhere('action', 'action.backup-destination-create');
    expect(json_decode((string) $createRow->changed, true))->toContain('credentials.secret_access_key')
        ->toContain('name');

    $haystack = implode("\n", [
        $response->getContent(), $list->getContent(), $status->getContent(),
        $audit->toJson(), implode("\n", $this->logs),
    ]);
    expect($haystack)->not->toContain($credentials['secret_access_key'])
        ->and($haystack)->not->toContain($credentials['access_key_id']);
});

test('a blank or omitted secret on update keeps the stored value', function (): void {
    $credentials = bapiFakeS3Credentials();
    $uuid = (string) ($this->createS3)($credentials)->assertStatus(201)->json('data.uuid');

    $this->putJson(BAPI_BASE . '/destinations/' . $uuid, [
        'name' => 'R2 renamed',
        'credentials' => ['access_key_id' => '', 'secret_access_key' => ''],
    ])->assertOk()->assertJsonPath('data.name', 'R2 renamed')
        ->assertJsonPath('data.credentials_set.secret_access_key', true);

    $this->putJson(BAPI_BASE . '/destinations/' . $uuid, ['retention_days' => 7])
        ->assertOk()->assertJsonPath('data.retention_days', 7);

    $stored = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    expect($stored->credentials)->toBe($credentials)->and($stored->name)->toBe('R2 renamed');

    // A new secret replaces only that field.
    $this->putJson(BAPI_BASE . '/destinations/' . $uuid, ['credentials' => ['secret_access_key' => 'fake-rotated-' . Str::random(20)]])
        ->assertOk();
    $rotated = BackupDestination::query()->where('uuid', $uuid)->firstOrFail()->credentials;
    expect($rotated['access_key_id'])->toBe($credentials['access_key_id'])
        ->and($rotated['secret_access_key'])->not->toBe($credentials['secret_access_key']);

    $updates = DB::table(CoreTableConstant::AUDIT_LOGS)->where('action', 'action.backup-destination-update')->get();
    expect($updates)->toHaveCount(3)
        ->and(json_decode((string) $updates[0]->changed, true))->toBe(['name'])
        ->and(json_decode((string) $updates[2]->changed, true))->toBe(['credentials.secret_access_key']);
});

test('retention not above the lock is refused with 422', function (): void {
    ($this->createS3)(bapiFakeS3Credentials(), ['retention_days' => 2, 'lock_days' => 2])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_RETENTION_NOT_ABOVE_LOCK');
    expect(BackupDestination::query()->count())->toBe(0);

    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $this->putJson(BAPI_BASE . '/destinations/' . $uuid, ['lock_days' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_RETENTION_NOT_ABOVE_LOCK');
    expect(BackupDestination::query()->where('uuid', $uuid)->value('lock_days'))->toBe(2);
});

test('driver validation errors answer 422 with the driver code', function (): void {
    ($this->createS3)(bapiFakeS3Credentials(), ['config' => ['endpoint' => 'http://plain.example.test', 'bucket' => 'b']])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_ENDPOINT_NOT_HTTPS');
});

test('delete is refused while the destination holds stored copies', function (): void {
    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $destination = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    $backup = Backup::query()->create(['reason' => 'manual', 'status' => 'succeeded', 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz']);
    BackupCopy::query()->create(['backup_id' => $backup->id, 'destination_id' => $destination->id, 'path' => 'a/b.sql.gz', 'status' => BackupCopy::STATUS_STORED, 'uploaded_at' => now()]);

    $this->deleteJson(BAPI_BASE . '/destinations/' . $uuid)
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'BACKUP_DESTINATION_IN_USE');
    expect(BackupDestination::query()->where('uuid', $uuid)->exists())->toBeTrue();
});

test('delete works for a destination without held copies', function (): void {
    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $destination = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    $backup = Backup::query()->create(['reason' => 'manual', 'status' => 'succeeded', 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz']);
    BackupCopy::query()->create(['backup_id' => $backup->id, 'destination_id' => $destination->id, 'path' => 'a/old.sql.gz', 'status' => BackupCopy::STATUS_PRUNED, 'uploaded_at' => now()->subDays(9)]);

    $this->deleteJson(BAPI_BASE . '/destinations/' . $uuid)->assertOk();
    expect(BackupDestination::query()->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table(CoreTableConstant::AUDIT_LOGS)->where('action', 'action.backup-destination-delete')->count())->toBe(1);

    $this->deleteJson(BAPI_BASE . '/destinations/' . Str::uuid())
        ->assertStatus(404)
        ->assertJsonPath('error_code', 'BACKUP_DESTINATION_NOT_FOUND');
});

test('the test endpoint probes a local destination in a temp dir', function (): void {
    $root = ($this->tempDir)();
    ($this->asWebmaster)();
    $uuid = (string) $this->postJson(BAPI_BASE . '/destinations', [
        'name' => 'Local disk',
        'driver' => 'local',
        'config' => ['root' => $root],
        'retention_days' => 3,
        'lock_days' => 0,
    ])->assertStatus(201)->json('data.uuid');

    $this->postJson(BAPI_BASE . '/destinations/' . $uuid . '/test')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.write', true)
        ->assertJsonPath('data.read', true)
        ->assertJsonPath('data.delete', true)
        ->assertJsonPath('data.lock_active', false);

    expect(DB::table(CoreTableConstant::AUDIT_LOGS)->where('action', 'action.backup-destination-test')->count())->toBe(1);
});

test('run dispatches the backup job and returns the task uuid', function (): void {
    Queue::fake();
    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $destination = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();

    $response = $this->postJson(BAPI_BASE . '/run', ['destinations' => [$uuid]])->assertStatus(202);
    $taskUuid = (string) $response->json('data.task_uuid');

    expect(BackgroundTask::query()->where('uuid', $taskUuid)->value('type'))->toBe('core.backup');
    expect($response->json('data'))->toHaveKey('backup_uuid');
    Queue::assertPushedOn('backups', RunBackupJob::class, fn (RunBackupJob $job): bool => $job->taskUuid === $taskUuid
        && $job->reason === Backup::REASON_MANUAL && $job->userId === $this->webmaster->id && $job->destinationIds === [$destination->id]);
    expect(DB::table(CoreTableConstant::AUDIT_LOGS)->where('action', 'action.backup-run')->count())->toBe(1);

    $this->postJson(BAPI_BASE . '/run', ['destinations' => [(string) Str::uuid()]])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BACKUP_DESTINATION_NOT_FOUND');
});

test('run maps dispatcher refusals to an error code', function (): void {
    Queue::fake();
    config(['queue.default' => 'sync']);
    ($this->asWebmaster)();

    $this->postJson(BAPI_BASE . '/run')
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'BACKUP_QUEUE_SYNC');
    Queue::assertNothingPushed();
});

test('history lists backups with their copies, newest first', function (): void {
    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $destination = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    $old = Backup::query()->create(['reason' => 'manual', 'status' => 'failed', 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'error_code' => 'BACKUP_DUMP_FAILED']);
    $new = Backup::query()->create(['reason' => 'scheduled', 'status' => 'succeeded', 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'background_task_uuid' => (string) Str::uuid()]);
    BackupCopy::query()->create(['backup_id' => $new->id, 'destination_id' => $destination->id, 'path' => 'p/n.sql.gz', 'status' => BackupCopy::STATUS_STORED, 'size_bytes' => 42, 'uploaded_at' => now()]);

    $response = $this->getJson(BAPI_BASE . '?per_page=10')->assertOk();
    $response->assertJsonPath('data.meta.total', 2)
        ->assertJsonPath('data.data.0.uuid', $new->uuid)
        ->assertJsonPath('data.data.0.copies.0.destination.uuid', $uuid)
        ->assertJsonPath('data.data.0.copies.0.status', 'stored')
        ->assertJsonPath('data.data.1.uuid', $old->uuid)
        ->assertJsonPath('data.data.1.error_code', 'BACKUP_DUMP_FAILED');

    $this->getJson(BAPI_BASE . '?task_uuid=' . $new->background_task_uuid)
        ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.uuid', $new->uuid);
});

test('status shows the nightly server status, last backup per destination, schedules and drivers', function (): void {
    $dir = ($this->tempDir)();
    $file = $dir . '/server-nightly.json';
    file_put_contents($file, json_encode([
        'schema' => 1, 'label' => '215', 'date' => now()->toDateString(), 'finished_at' => now()->toIso8601String(),
        'objects' => [['key' => '215/x/db.sql.zst', 'database' => 'db', 'tables_expected' => 3, 'tables_dumped' => 3, 'failed' => false]],
    ]));
    $this->app->instance(ServerNightlyStatus::class, new ServerNightlyStatus($file));

    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');
    $destination = BackupDestination::query()->where('uuid', $uuid)->firstOrFail();
    $backup = Backup::query()->create(['reason' => 'manual', 'status' => 'succeeded', 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz']);
    BackupCopy::query()->create(['backup_id' => $backup->id, 'destination_id' => $destination->id, 'path' => 'p/s.sql.gz', 'status' => BackupCopy::STATUS_STORED, 'uploaded_at' => now()]);

    $response = $this->getJson(BAPI_BASE . '/status')->assertOk();
    $response->assertJsonPath('data.server_nightly.state', 'ok')
        ->assertJsonPath('data.server_nightly.label', '215')
        ->assertJsonPath('data.destinations.0.uuid', $uuid)
        ->assertJsonPath('data.destinations.0.last_backup.uuid', $backup->uuid)
        ->assertJsonPath('data.schedules.0.destination_uuid', $uuid)
        ->assertJsonPath('data.schedules.0.schedule', 'daily@02:30');

    $drivers = collect($response->json('data.drivers'))->keyBy('key');
    expect($drivers->keys()->all())->toContain('s3', 'local', 'sftp', 'webdav', 'gdrive', 'onedrive')
        ->and($drivers['s3']['fields'])->toHaveKey('bucket')
        ->and($drivers['s3']['credential_fields'])->toBe(['access_key_id', 'secret_access_key'])
        ->and($drivers['gdrive']['credential_fields'])->toBe(['client_secret']);
});

test('the destinations list shows credential flags and stored copy counts', function (): void {
    $uuid = (string) ($this->createS3)(bapiFakeS3Credentials())->assertStatus(201)->json('data.uuid');

    $this->getJson(BAPI_BASE . '/destinations')
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $uuid)
        ->assertJsonPath('data.0.credentials_set.secret_access_key', true)
        ->assertJsonPath('data.0.held_copies', 0)
        ->assertJsonMissingPath('data.0.credentials')
        ->assertJsonMissingPath('data.0.id');
});
