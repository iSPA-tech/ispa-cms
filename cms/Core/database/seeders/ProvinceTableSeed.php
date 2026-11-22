<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use App\Enums\StatusEnum;
use App\Models\Province;
use Cms\Core\Services\Seeders\SeedFingerprintService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProvinceTableSeed extends Seeder
{
    private const FINGERPRINT_KEY = 'core_province_export';
    private const SEEDER_VERSION = 1;
    private const SOURCE_FILE = 'cms/Core/database/seeders/exported/provinces.php';

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $records = require __DIR__ . '/exported/provinces.php';
        $fingerprints = app(SeedFingerprintService::class);
        $sourceHash = $fingerprints->sourceHash([self::SOURCE_FILE], [
            'seeder_version' => self::SEEDER_VERSION,
        ]);
        $sourceCount = count($records);

        if ($fingerprints->shouldSkip(
            self::FINGERPRINT_KEY,
            self::class,
            $sourceHash,
            $sourceCount,
            self::SEEDER_VERSION,
            static fn (): bool => Province::withTrashed()->count() > 0
        )) {
            $this->command?->info('Province list unchanged; skipped.');

            return;
        }

        $legacyIdMap = [];

        DB::transaction(function () use ($records, &$legacyIdMap): void {
            foreach ($records as $index => $record) {
                $province = $this->persistProvince($record, null);
                $legacyIdMap[$this->legacyIdFor($record, $index)] = (int) $province->getKey();
            }

            foreach ($records as $record) {
                $this->persistProvince($record, $this->mappedParentId($record, $legacyIdMap));
            }
        });

        $this->syncSequence();
        $fingerprints->markCompleted(
            self::FINGERPRINT_KEY,
            self::class,
            $sourceHash,
            $sourceCount,
            self::SEEDER_VERSION,
            ['source_file' => self::SOURCE_FILE]
        );

        $this->command?->info('Province list synced!');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function persistProvince(array $record, ?int $parentId): Province
    {
        $province = Province::withTrashed()->firstOrNew(['uuid' => $record['uuid']]);

        $province->forceFill($this->payloadFor($record, $parentId));
        $province->save();

        return $province;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function payloadFor(array $record, ?int $parentId): array
    {
        $data = collect($record)->except(['id', 'parent_id', 'created_at', 'updated_at', 'deleted_at'])->toArray();
        if (($data['status'] ?? null) === 'published') {
            $data['status'] = StatusEnum::PUBLISHED->value;
        }

        return array_merge($data, [
            'parent_id' => $parentId,
            'created_at' => $record['created_at'] ?? now(),
            'updated_at' => $record['updated_at'] ?? now(),
            'deleted_at' => $record['deleted_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function legacyIdFor(array $record, int $index): int
    {
        return isset($record['id']) && is_numeric($record['id'])
            ? (int) $record['id']
            : $index + 1;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, int> $legacyIdMap
     */
    private function mappedParentId(array $record, array $legacyIdMap): ?int
    {
        $parentId = $record['parent_id'] ?? null;
        if (! is_numeric($parentId)) {
            return null;
        }

        return $legacyIdMap[(int) $parentId] ?? null;
    }

    private function syncSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [(new Province())->getTable(), 'id']
        );

        $sequenceName = $sequence->sequence_name ?? null;
        if (! is_string($sequenceName) || $sequenceName === '') {
            return;
        }

        $maxId = Province::query()->max('id');
        if (! is_numeric($maxId)) {
            return;
        }

        DB::statement('SELECT setval(?::regclass, ?, true)', [$sequenceName, (int) $maxId]);
    }
}
