<?php

namespace Cms\Core\Database\Seeders;

use App\Models\Province;
use Cms\Core\Services\Seeders\SeedFingerprintService;
use Cms\Core\Support\ThanglequocAdministrativeUnits;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class ThanglequocProvinceSeeder extends Seeder
{
    private const COUNTRY_TYPE = 0;
    private const PROVINCE_TYPES = [1, 2];
    private const FINGERPRINT_KEY = 'core_thanglequoc_provinces';
    private const SEEDER_VERSION = 1;

    public function run(): void
    {
        $dataset = app(ThanglequocAdministrativeUnits::class);
        $provinces = $dataset->provinces();
        $sourceCount = $this->sourceCount($provinces);
        $fingerprints = app(SeedFingerprintService::class);
        $sourceHash = $fingerprints->sourceHash([ThanglequocAdministrativeUnits::SOURCE_DATA_FILE], [
            'administrative_version' => ThanglequocAdministrativeUnits::ADMINISTRATIVE_VERSION,
            'release_version' => ThanglequocAdministrativeUnits::RELEASE_VERSION,
            'seeder_version' => self::SEEDER_VERSION,
        ]);

        if ($fingerprints->shouldSkip(
            self::FINGERPRINT_KEY,
            self::class,
            $sourceHash,
            $sourceCount,
            self::SEEDER_VERSION,
            static fn (): bool => Province::withTrashed()
                ->where('uid', 'like', 'tq%')
                ->count() >= $sourceCount
        )) {
            $this->command?->info('Thanglequoc province hierarchy unchanged; skipped.');

            return;
        }

        $provinceIdByCode = [];

        DB::transaction(function () use ($provinces, &$provinceIdByCode): void {
            $countryId = $this->replaceLegacyHierarchy();

            foreach ($provinces as $provinceRecord) {
                $province = $this->persistProvince($provinceRecord, $countryId);
                $provinceIdByCode[(string) $provinceRecord['Code']] = (int) $province->getKey();
            }

            foreach ($provinces as $provinceRecord) {
                $parentId = $provinceIdByCode[(string) $provinceRecord['Code']] ?? null;
                if ($parentId === null) {
                    continue;
                }

                foreach ($provinceRecord['Wards'] ?? [] as $wardRecord) {
                    if (is_array($wardRecord)) {
                        $this->persistWard($provinceRecord, $wardRecord, $parentId);
                    }
                }
            }
        });

        $this->syncSequence();
        $fingerprints->markCompleted(
            self::FINGERPRINT_KEY,
            self::class,
            $sourceHash,
            $sourceCount,
            self::SEEDER_VERSION,
            [
                'source_file' => ThanglequocAdministrativeUnits::SOURCE_DATA_FILE,
                'administrative_version' => ThanglequocAdministrativeUnits::ADMINISTRATIVE_VERSION,
                'release_version' => ThanglequocAdministrativeUnits::RELEASE_VERSION,
            ]
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function persistProvince(array $record, ?int $parentId): Province
    {
        $code = (string) $record['Code'];
        $province = Province::query()->withTrashed()->firstOrNew([
            'uuid' => $this->uuidFor('province', $code),
        ]);
        $legacyConfig = $this->legacyConfigFor($province->exists ? $province->config : null);

        $province->forceFill([
            'uuid' => $province->uuid ?: $this->uuidFor('province', $code),
            'uid' => $province->uid ?: $this->uidFor('province', $code),
            'name' => (string) $record['FullName'],
            'global_name' => (string) $record['FullNameEn'],
            'slug' => $province->slug ?: $this->slugFor('province', $code, (string) $record['CodeName']),
            'type' => $this->provinceType((string) $record['AdministrativeUnitShortName']),
            'parent_id' => $parentId,
            'language_id' => null,
            'maps_id' => null,
            'longitude' => null,
            'latitude' => null,
            'priority' => 0,
            'config' => $this->configFor('province', ThanglequocAdministrativeUnits::metadataForProvince($record), $legacyConfig),
            'status' => 'status_published',
        ]);
        $province->save();

        if ($province->trashed()) {
            $province->restore();
        }

        return $province;
    }

    /**
     * @param array<string, mixed> $provinceRecord
     * @param array<string, mixed> $wardRecord
     */
    private function persistWard(array $provinceRecord, array $wardRecord, int $parentId): Province
    {
        $code = (string) $wardRecord['Code'];
        $ward = Province::query()->withTrashed()->firstOrNew([
            'uuid' => $this->uuidFor('ward', $code),
        ]);

        $ward->forceFill([
            'uid' => $this->uidFor('ward', $code),
            'name' => (string) $wardRecord['FullName'],
            'global_name' => (string) $wardRecord['FullNameEn'],
            'slug' => $this->slugFor('ward', $code, (string) $wardRecord['CodeName']),
            'type' => 4,
            'parent_id' => $parentId,
            'language_id' => null,
            'maps_id' => null,
            'longitude' => null,
            'latitude' => null,
            'priority' => 0,
            'config' => $this->configFor('ward', ThanglequocAdministrativeUnits::metadataForWard($provinceRecord, $wardRecord)),
            'status' => 'status_published',
        ]);
        $ward->save();

        if ($ward->trashed()) {
            $ward->restore();
        }

        return $ward;
    }

    private function provinceType(string $type): int
    {
        return $type === 'Thành phố' ? 2 : 1;
    }

    /**
     * @param list<array<string, mixed>> $provinces
     */
    private function sourceCount(array $provinces): int
    {
        $count = count($provinces);

        foreach ($provinces as $province) {
            foreach ($province['Wards'] ?? [] as $ward) {
                if (is_array($ward)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function configFor(string $level, array $metadata, ?array $legacyConfig = null): array
    {
        $config = [
            'flag' => null,
            'zipCode' => null,
            'webDefault' => false,
            'webShow' => false,
            'url' => null,
            'organization' => null,
            'timeZone' => null,
            'phoneCode' => null,
            'areaName' => null,
            'is_legacy' => false,
            'administrative_dataset' => ThanglequocAdministrativeUnits::DATASET_KEY,
            'administrative_version' => ThanglequocAdministrativeUnits::ADMINISTRATIVE_VERSION,
            'administrative_level' => $level,
            'thanglequoc' => $metadata,
        ];

        if ($legacyConfig !== null && $legacyConfig !== []) {
            $config['legacy_config'] = $legacyConfig;
        }

        return $config;
    }

    /**
     * @return int|null Active country row id, when the legacy seed already has one.
     */
    private function replaceLegacyHierarchy(): ?int
    {
        $countryIds = Province::query()
            ->withTrashed()
            ->where('type', self::COUNTRY_TYPE)
            ->pluck('id');
        $countryId = $countryIds
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->sort()
            ->first();

        $provinceCandidateIds = Province::query()
            ->withTrashed()
            ->whereIn('type', self::PROVINCE_TYPES)
            ->where(static function ($query) use ($countryIds): void {
                $query->whereNull('parent_id');

                if ($countryIds->isNotEmpty()) {
                    $query->orWhereIn('parent_id', $countryIds);
                }
            })
            ->pluck('id');

        Province::query()
            ->withTrashed()
            ->where('type', '!=', self::COUNTRY_TYPE)
            ->when(
                $provinceCandidateIds->isNotEmpty(),
                static fn ($query) => $query->whereNotIn('id', $provinceCandidateIds)
            )
            ->forceDelete();

        if ($provinceCandidateIds->isEmpty()) {
            return new Collection();
        }

        Province::query()
            ->withTrashed()
            ->whereIn('id', $provinceCandidateIds)
            ->delete();

        return is_int($countryId) ? $countryId : null;
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>|null
     */
    private function legacyConfigFor(?array $config): ?array
    {
        if ($config === null || $config === []) {
            return null;
        }

        if (($config['administrative_dataset'] ?? null) === ThanglequocAdministrativeUnits::DATASET_KEY) {
            $legacyConfig = $config['legacy_config'] ?? null;

            return is_array($legacyConfig) ? $legacyConfig : null;
        }

        return $config;
    }

    private function uuidFor(string $level, string $code): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, ThanglequocAdministrativeUnits::SOURCE_REPO . ":{$level}:{$code}")->toString();
    }

    private function uidFor(string $level, string $code): string
    {
        return 'tq' . substr(sha1("{$level}:{$code}"), 0, 8);
    }

    private function slugFor(string $level, string $code, string $codeName): string
    {
        return Str::slug("thanglequoc 2026 {$level} {$code} {$codeName}");
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
