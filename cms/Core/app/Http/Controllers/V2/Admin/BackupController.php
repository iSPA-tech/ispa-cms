<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Backup\RunBackupRequest;
use Cms\Core\Http\Requests\Backup\StoreBackupDestinationRequest;
use Cms\Core\Http\Requests\Backup\UpdateBackupDestinationRequest;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupDispatcher;
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Cms\Core\Services\Backup\Destinations\DestinationRules;
use Cms\Core\Services\Backup\Destinations\DestinationTester;
use Cms\Core\Services\Backup\ServerNightlyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Webmaster-only admin API for app backups: destinations (secrets are write-only), destination probes, manual runs,
 * history and an overview of the nightly server backup. Access is enforced by the `admin.webmaster` route middleware.
 */
class BackupController extends AdminController
{
    /** Credential fields managed by the OAuth connection flow; never written through this API. */
    private const OAUTH_MANAGED_CREDENTIALS = ['refresh_token'];

    /** Copy statuses that still hold (or may hold) an object on the destination. */
    private const HELD_COPY_STATUSES = [
        BackupCopy::STATUS_UPLOADING, BackupCopy::STATUS_STORED, BackupCopy::STATUS_FAILED, BackupCopy::STATUS_PRUNE_REFUSED,
    ];

    /** Plan default retention for a new destination. */
    private const DEFAULT_RETENTION_DAYS = 3;

    /**
     * @param DestinationRegistry $registry Known destination drivers.
     * @param DestinationRules $rules Driver and retention validation.
     */
    public function __construct(
        private readonly DestinationRegistry $registry,
        private readonly DestinationRules $rules,
    ) {
    }

    /**
     * Overview: nightly server backup status, last app backup per destination, schedules and available drivers.
     *
     * @param ServerNightlyStatus $nightly Reader of the server status file.
     *
     * @return mixed Admin API response.
     */
    public function status(ServerNightlyStatus $nightly): mixed
    {
        $destinations = $this->destinationQuery()->get();

        return ires()->data([
            'server_nightly' => $nightly->read(),
            'destinations' => $destinations->map(fn (BackupDestination $d): array => [
                'uuid' => $d->uuid,
                'name' => $d->name,
                'driver' => $d->driver,
                'enabled' => (bool) $d->enabled,
                'last_success_at' => $d->last_success_at?->toIso8601String(),
                'last_failure_at' => $d->last_failure_at?->toIso8601String(),
                'last_error_code' => $d->last_error_code,
                'last_backup' => $this->lastBackup($d),
            ])->values()->all(),
            'schedules' => $destinations->filter(fn (BackupDestination $d): bool => is_string($d->schedule) && $d->schedule !== '')
                ->map(fn (BackupDestination $d): array => [
                    'destination_uuid' => $d->uuid,
                    'name' => $d->name,
                    'schedule' => $d->schedule,
                    'enabled' => (bool) $d->enabled,
                ])->values()->all(),
            'drivers' => array_map(fn (string $key): array => [
                'key' => $key,
                'fields' => $this->registry->get($key)->fields(),
                'credential_fields' => $this->writableCredentialFields($key),
            ], $this->registry->keys()),
        ]);
    }

    /**
     * List destinations (credentials only as `credentials_set` flags).
     *
     * @return mixed Admin API response.
     */
    public function destinations(): mixed
    {
        return ires()->data($this->destinationQuery()->get()
            ->map(fn (BackupDestination $d): array => $this->present($d))->values()->all());
    }

    /**
     * Create a destination after driver and retention validation.
     *
     * @param StoreBackupDestinationRequest $request Validated input.
     *
     * @return mixed Admin API response (201 with the destination, or 422 with an errorCode).
     */
    public function store(StoreBackupDestinationRequest $request): mixed
    {
        $driver = (string) $request->validated('driver');
        $config = $this->filterConfig($driver, (array) ($request->validated('config') ?? []));
        $credentials = $this->mergeCredentials($driver, [], (array) ($request->validated('credentials') ?? []));
        $retention = (int) ($request->validated('retention_days') ?? self::DEFAULT_RETENTION_DAYS);
        $lock = (int) ($request->validated('lock_days') ?? 0);

        $errors = $this->rules->validate($driver, $config, $credentials, $retention, $lock);
        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $destination = BackupDestination::query()->create([
            'name' => (string) $request->validated('name'),
            'driver' => $driver,
            'config' => $config,
            'credentials' => $credentials,
            'retention_days' => $retention,
            'lock_days' => $lock,
            'schedule' => $request->validated('schedule'),
            'use_for_safety' => (bool) ($request->validated('use_for_safety') ?? false),
            'enabled' => (bool) ($request->validated('enabled') ?? true),
            'created_by' => $request->user()?->id,
        ]);

        $changed = ['name', 'driver', 'retention_days', 'lock_days', 'schedule', 'use_for_safety', 'enabled'];
        foreach (array_keys($config) as $key) {
            $changed[] = 'config.' . $key;
        }
        foreach (array_keys($credentials) as $key) {
            $changed[] = 'credentials.' . $key;
        }
        $this->audit('backup-destination-create', $changed, BackupDestination::class, (int) $destination->id, 'fa fa-plus', 'success');

        return ires()->created($this->present($this->reload($destination)));
    }

    /**
     * Update a destination. Omitted fields stay; a blank or omitted secret keeps the stored one.
     *
     * @param UpdateBackupDestinationRequest $request Validated input.
     * @param string $uuid Destination uuid.
     *
     * @return mixed Admin API response.
     */
    public function update(UpdateBackupDestinationRequest $request, string $uuid): mixed
    {
        $destination = BackupDestination::query()->where('uuid', $uuid)->first();
        if ($destination === null) {
            return $this->notFound();
        }

        $input = $request->validated();
        $driver = (string) $destination->driver;
        $oldConfig = is_array($destination->config) ? $destination->config : [];
        $oldCredentials = is_array($destination->credentials) ? $destination->credentials : [];

        $config = array_key_exists('config', $input)
            ? array_replace($oldConfig, $this->filterConfig($driver, (array) ($input['config'] ?? [])))
            : $oldConfig;
        $credentials = $this->mergeCredentials($driver, $oldCredentials, (array) ($input['credentials'] ?? []));
        $retention = (int) ($input['retention_days'] ?? $destination->retention_days);
        $lock = (int) ($input['lock_days'] ?? $destination->lock_days);

        $errors = $this->rules->validate($driver, $config, $credentials, $retention, $lock);
        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $changed = [];
        $attributes = [];
        foreach (['name', 'schedule', 'use_for_safety', 'enabled'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $destination->getAttribute($field)) {
                $attributes[$field] = $input[$field];
                $changed[] = $field;
            }
        }
        foreach (['retention_days' => $retention, 'lock_days' => $lock] as $field => $value) {
            if ($value !== (int) $destination->getAttribute($field)) {
                $attributes[$field] = $value;
                $changed[] = $field;
            }
        }
        foreach (array_keys($config) as $key) {
            if (($oldConfig[$key] ?? null) !== $config[$key]) {
                $changed[] = 'config.' . $key;
            }
        }
        if ($config !== $oldConfig) {
            $attributes['config'] = $config;
        }
        foreach (array_keys($credentials) as $key) {
            if (($oldCredentials[$key] ?? null) !== $credentials[$key]) {
                $changed[] = 'credentials.' . $key;
            }
        }
        if ($credentials !== $oldCredentials) {
            $attributes['credentials'] = $credentials;
        }

        if ($attributes !== []) {
            $destination->fill($attributes)->save();
            $this->audit('backup-destination-update', $changed, BackupDestination::class, (int) $destination->id, 'fa fa-pencil', 'info');
        }

        return ires()->data($this->present($this->reload($destination)));
    }

    /**
     * Probe a destination (write, read back, delete) and report whether an object lock is active.
     *
     * @param string $uuid Destination uuid.
     * @param DestinationTester $tester Probe runner (its results never carry exception text).
     *
     * @return mixed Admin API response with the probe result.
     */
    public function test(string $uuid, DestinationTester $tester): mixed
    {
        $destination = BackupDestination::query()->where('uuid', $uuid)->first();
        if ($destination === null) {
            return $this->notFound();
        }

        $result = $tester->test($destination);
        $this->audit('backup-destination-test', [], BackupDestination::class, (int) $destination->id, 'fa fa-plug', $result['ok'] ? 'success' : 'danger');

        return ires()->data($result);
    }

    /**
     * Delete a destination that holds no copies; one that still holds copies must be disabled instead.
     *
     * @param string $uuid Destination uuid.
     *
     * @return mixed Admin API response (409 BACKUP_DESTINATION_IN_USE while copies are held).
     */
    public function destroy(string $uuid): mixed
    {
        $destination = BackupDestination::query()->where('uuid', $uuid)->first();
        if ($destination === null) {
            return $this->notFound();
        }

        if ($destination->copies()->whereIn('status', self::HELD_COPY_STATUSES)->exists()) {
            return ires()->conflict('The destination still holds backup copies; disable it instead of deleting it.')
                ->errorCode('BACKUP_DESTINATION_IN_USE');
        }

        $id = (int) $destination->id;
        DB::transaction(function () use ($destination): void {
            // Only pruned copy rows remain; their objects are gone, so the rows go with the destination.
            $destination->copies()->delete();
            $destination->delete();
        });
        $this->audit('backup-destination-delete', [], BackupDestination::class, $id, 'fa fa-trash', 'danger');

        return ires()->ok(null, 'The backup destination was deleted.');
    }

    /**
     * Paginated backup history with copies, newest first; optional filters `task_uuid` and `status`.
     *
     * @param Request $request Query: per_page (1-100), page, task_uuid, status.
     *
     * @return mixed Admin API response with `data` and `meta`.
     */
    public function index(Request $request): mixed
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $query = Backup::query()->with(['copies' => fn ($q) => $q->orderBy('id'), 'copies.destination'])->orderByDesc('id');

        $taskUuid = $request->query('task_uuid');
        if (is_string($taskUuid) && $taskUuid !== '') {
            $query->where('background_task_uuid', $taskUuid);
        }
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $page = $query->paginate($perPage)->through(fn (Backup $b): array => $this->presentBackup($b));

        return ires()->fromPaginator($page, false);
    }

    /**
     * Queue a manual backup as a background task.
     *
     * The backup row is created by the job when it starts, so `backup_uuid` is null here; the client follows the task
     * and finds the backup with `GET backups?task_uuid=`.
     *
     * @param RunBackupRequest $request Optional destination uuids.
     * @param BackupDispatcher $dispatcher Queue checks + task creation.
     *
     * @return mixed Admin API response (202 with task_uuid and backup_uuid).
     */
    public function run(RunBackupRequest $request, BackupDispatcher $dispatcher): mixed
    {
        $uuids = array_values(array_unique((array) ($request->validated('destinations') ?? [])));
        $ids = [];
        if ($uuids !== []) {
            $found = BackupDestination::query()->whereIn('uuid', $uuids)->get(['id', 'uuid', 'enabled']);
            if ($found->count() !== count($uuids)) {
                return ires()->error()->code(422)->errorCode('BACKUP_DESTINATION_NOT_FOUND')
                    ->message('A chosen backup destination was not found.');
            }
            if ($found->contains(fn (BackupDestination $d): bool => !$d->enabled)) {
                return ires()->error()->code(422)->errorCode('BACKUP_DESTINATION_DISABLED')
                    ->message('A chosen backup destination is disabled.');
            }
            $ids = $found->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        }

        $userId = $request->user()?->id;
        try {
            $task = $dispatcher->dispatch(Backup::REASON_MANUAL, $userId !== null ? (int) $userId : null, $ids);
        } catch (RuntimeException $exception) {
            if (preg_match('/^BACKUP_[A-Z0-9_]+$/', $exception->getMessage()) !== 1) {
                throw $exception;
            }

            return ires()->conflict('The queue cannot run a backup safely; ask the server administrator to check the queue settings.')
                ->errorCode($exception->getMessage());
        }

        $this->audit('backup-run', $uuids !== [] ? ['destinations'] : [], BackgroundTask::class, (int) $task->id, 'fa fa-database', 'info');

        return ires()->accepted(['task_uuid' => (string) $task->uuid, 'backup_uuid' => null]);
    }

    /**
     * Destinations with the count of copies they still hold, oldest first.
     *
     * @return Builder<BackupDestination>
     */
    private function destinationQuery(): Builder
    {
        return BackupDestination::query()
            ->withCount(['copies as held_copies' => fn ($q) => $q->whereIn('status', self::HELD_COPY_STATUSES)])
            ->orderBy('id');
    }

    /**
     * Reload a destination with its held-copy count.
     */
    private function reload(BackupDestination $destination): BackupDestination
    {
        return $this->destinationQuery()->whereKey($destination->getKey())->firstOrFail();
    }

    /**
     * Public shape of a destination; credentials appear only as `credentials_set` flags.
     *
     * @return array<string, mixed>
     */
    private function present(BackupDestination $d): array
    {
        $set = $d->credentialsSet();
        $flags = [];
        $fields = $this->registry->has((string) $d->driver) ? $this->registry->get((string) $d->driver)->credentialFields() : [];
        foreach ($fields as $field) {
            $flags[$field] = $set[$field] ?? false;
        }

        return [
            'uuid' => $d->uuid,
            'name' => $d->name,
            'driver' => $d->driver,
            'config' => is_array($d->config) ? $d->config : [],
            'credentials_set' => $flags,
            'retention_days' => (int) $d->retention_days,
            'lock_days' => (int) $d->lock_days,
            'schedule' => $d->schedule,
            'use_for_safety' => (bool) $d->use_for_safety,
            'enabled' => (bool) $d->enabled,
            'held_copies' => (int) ($d->held_copies ?? 0),
            'last_success_at' => $d->last_success_at?->toIso8601String(),
            'last_failure_at' => $d->last_failure_at?->toIso8601String(),
            'last_error_code' => $d->last_error_code,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Public shape of a backup with its copies.
     *
     * @return array<string, mixed>
     */
    private function presentBackup(Backup $b): array
    {
        return [
            'uuid' => $b->uuid,
            'reason' => $b->reason,
            'status' => $b->status,
            'db_driver' => $b->db_driver,
            'db_name' => $b->db_name,
            'format' => $b->format,
            'tables_expected' => $b->tables_expected,
            'tables_dumped' => $b->tables_dumped,
            'size_bytes' => $b->size_bytes,
            'sha256' => $b->sha256,
            'background_task_uuid' => $b->background_task_uuid,
            'error_code' => $b->error_code,
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'verified_at' => $b->verified_at?->toIso8601String(),
            'restore_tested_at' => $b->restore_tested_at?->toIso8601String(),
            'created_at' => $b->created_at?->toIso8601String(),
            'copies' => $b->copies->map(fn (BackupCopy $c): array => [
                'kind' => $c->kind,
                'status' => $c->status,
                'path' => $c->path,
                'size_bytes' => $c->size_bytes,
                'uploaded_at' => $c->uploaded_at?->toIso8601String(),
                'pruned_at' => $c->pruned_at?->toIso8601String(),
                'error_code' => $c->error_code,
                'destination' => $c->destination === null ? null : [
                    'uuid' => $c->destination->uuid,
                    'name' => $c->destination->name,
                    'driver' => $c->destination->driver,
                ],
            ])->values()->all(),
        ];
    }

    /**
     * Last backup copied to a destination (any status), or null.
     *
     * @return array<string, mixed>|null
     */
    private function lastBackup(BackupDestination $d): ?array
    {
        $copy = $d->copies()->where('kind', BackupCopy::KIND_BACKUP)->whereNotNull('backup_id')
            ->with('backup')->orderByDesc('id')->first();
        if ($copy === null || $copy->backup === null) {
            return null;
        }

        return [
            'uuid' => $copy->backup->uuid,
            'reason' => $copy->backup->reason,
            'status' => $copy->backup->status,
            'copy_status' => $copy->status,
            'finished_at' => $copy->backup->finished_at?->toIso8601String(),
            'uploaded_at' => $copy->uploaded_at?->toIso8601String(),
        ];
    }

    /**
     * Credential fields an operator may set for a driver (OAuth-managed tokens excluded).
     *
     * @return array<int, string>
     */
    private function writableCredentialFields(string $driver): array
    {
        return array_values(array_diff($this->registry->get($driver)->credentialFields(), self::OAUTH_MANAGED_CREDENTIALS));
    }

    /**
     * Keep only the driver's declared non-secret config fields.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function filterConfig(string $driver, array $config): array
    {
        return array_intersect_key($config, $this->registry->get($driver)->fields());
    }

    /**
     * Apply new secrets over the stored ones: only writable fields, and a blank value keeps the stored secret.
     *
     * @param array<string, mixed> $stored Current decrypted credentials.
     * @param array<string, mixed> $input Submitted credentials.
     * @return array<string, mixed>
     */
    private function mergeCredentials(string $driver, #[\SensitiveParameter] array $stored, #[\SensitiveParameter] array $input): array
    {
        $merged = $stored;
        foreach ($this->writableCredentialFields($driver) as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * 422 with the first validation code as errorCode and the full list in `data.errors`.
     *
     * @param array<int, string> $errors Machine codes.
     */
    private function invalid(array $errors): mixed
    {
        return ires()->error()->code(422)->errorCode($errors[0])
            ->message('The backup destination settings are not valid.')
            ->data(['errors' => array_values($errors)]);
    }

    /**
     * 404 for an unknown destination uuid.
     */
    private function notFound(): mixed
    {
        return ires()->notFound('The backup destination was not found.')->errorCode('BACKUP_DESTINATION_NOT_FOUND');
    }

    /**
     * Record an audit entry; `changed` lists field names only, never values.
     *
     * @param array<int, string> $changed Changed field names.
     */
    private function audit(string $action, array $changed, string $type, int $id, string $icon, string $color): void
    {
        event(new AuditLogEvent($action, array_values($changed), $type, $id, $icon, $color, (int) (auth()->id() ?? 0)));
    }
}
