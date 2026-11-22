<?php

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the per-site status file written by the nightly server backup script
 * (docs/ops/backup/ispa_backup.sh): storage/backups/server-nightly.json.
 *
 * The app only ever reads this local file; it never holds the backup bucket key.
 */
class ServerNightlyStatus
{
    /**
     * @param string|null $path       Status file path; defaults to storage/backups/server-nightly.json.
     * @param int         $staleHours Hours after finished_at when the status counts as stale.
     */
    public function __construct(private ?string $path = null, private int $staleHours = 30)
    {
        $this->path ??= storage_path('backups/server-nightly.json');
    }

    /**
     * Returns the normalised status. Never throws on missing or bad input.
     *
     * state is ok, failed, stale (older than the threshold, wins over ok/failed), unknown (no file)
     * or invalid (unreadable, malformed or wrong schema; see error).
     *
     * @return array{state: string, label: ?string, date: ?string, finished_at: ?string, objects: list<array{key: string, database: string, tables_expected: ?int, tables_dumped: ?int, failed: bool}>, error: ?string}
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return $this->result('unknown');
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return $this->result('invalid', error: 'Status file is not readable.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->result('invalid', error: 'Status file is not valid JSON.');
        }

        if (! is_array($data) || ($data['schema'] ?? null) !== 1) {
            return $this->result('invalid', error: 'Unsupported status file schema.');
        }
        if (! is_string($data['label'] ?? null) || ! is_string($data['date'] ?? null)
            || ! is_string($data['finished_at'] ?? null) || ! is_array($data['objects'] ?? null)
            || ! array_is_list($data['objects'])) {
            return $this->result('invalid', error: 'Status file is missing required fields.');
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $data['finished_at']) !== 1) {
                throw new \InvalidArgumentException('finished_at is not ISO-8601');
            }
            $finished = Carbon::parse($data['finished_at']);
        } catch (Throwable) {
            return $this->result('invalid', error: 'Status file has an invalid finished_at.');
        }

        $objects = [];
        foreach ($data['objects'] as $object) {
            $normalised = $this->object($object);
            if ($normalised === null) {
                return $this->result('invalid', error: 'Status file has a malformed object entry.');
            }
            $objects[] = $normalised;
        }

        $state = 'ok';
        foreach ($objects as $object) {
            if ($object['failed']) {
                $state = 'failed';
                break;
            }
        }
        if ($finished->lt(Carbon::now()->subHours($this->staleHours))) {
            $state = 'stale';
        }

        return $this->result($state, $data['label'], $data['date'], $data['finished_at'], $objects);
    }

    /**
     * Validates one object entry and keeps only the known fields.
     *
     * @return array{key: string, database: string, tables_expected: ?int, tables_dumped: ?int, failed: bool}|null
     */
    private function object(mixed $object): ?array
    {
        if (! is_array($object)
            || ! is_string($object['key'] ?? null) || ! is_string($object['database'] ?? null)
            || ! is_bool($object['failed'] ?? null)) {
            return null;
        }
        foreach (['tables_expected', 'tables_dumped'] as $field) {
            if (! array_key_exists($field, $object) || ($object[$field] !== null && ! is_int($object[$field]))) {
                return null;
            }
        }

        return [
            'key' => $object['key'],
            'database' => $object['database'],
            'tables_expected' => $object['tables_expected'],
            'tables_dumped' => $object['tables_dumped'],
            'failed' => $object['failed'],
        ];
    }

    /**
     * Builds the result array with the fixed key set.
     *
     * @param list<array{key: string, database: string, tables_expected: ?int, tables_dumped: ?int, failed: bool}> $objects
     * @return array{state: string, label: ?string, date: ?string, finished_at: ?string, objects: list<array{key: string, database: string, tables_expected: ?int, tables_dumped: ?int, failed: bool}>, error: ?string}
     */
    private function result(string $state, ?string $label = null, ?string $date = null, ?string $finishedAt = null, array $objects = [], ?string $error = null): array
    {
        return [
            'state' => $state,
            'label' => $label,
            'date' => $date,
            'finished_at' => $finishedAt,
            'objects' => $objects,
            'error' => $error,
        ];
    }
}
