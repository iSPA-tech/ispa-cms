<?php

namespace Cms\Core\Support;

use RuntimeException;

final class ThanglequocAdministrativeUnits
{
    public const DATASET_KEY = 'thanglequoc_vn_units';
    public const ADMINISTRATIVE_VERSION = '2026';
    public const SOURCE_REPO = 'thanglequoc/vietnamese-provinces-database';
    public const SOURCE_URL = 'https://github.com/thanglequoc/vietnamese-provinces-database';
    public const SOURCE_DATA_URL = 'https://raw.githubusercontent.com/thanglequoc/vietnamese-provinces-database/refs/heads/master/json/full_json_generated_data_vn_units.json';
    public const SOURCE_DATA_FILE = 'cms/Core/database/data/thanglequoc_vn_units.json';
    public const RELEASE_VERSION = 'v4.0.0';

    /** @var list<array<string, mixed>>|null */
    private ?array $records = null;

    /**
     * @return list<array<string, mixed>>
     */
    public function provinces(): array
    {
        return $this->records();
    }

    /**
     * @return array<string, array{province: array<string, mixed>, ward: array<string, mixed>}>
     */
    public function wardLookupByDatabaseCode(): array
    {
        $lookup = [];

        foreach ($this->records() as $province) {
            foreach ($province['Wards'] ?? [] as $ward) {
                if (! is_array($ward)) {
                    continue;
                }

                $lookup[self::databaseCode((string) ($ward['Code'] ?? ''))] = [
                    'province' => $province,
                    'ward' => $ward,
                ];
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $province
     * @return array<string, mixed>
     */
    public static function metadataForProvince(array $province): array
    {
        return [
            ...self::sourceMetadata(),
            'province' => self::provinceMetadata($province),
        ];
    }

    /**
     * @param array<string, mixed> $province
     * @param array<string, mixed> $ward
     * @return array<string, mixed>
     */
    public static function metadataForWard(array $province, array $ward): array
    {
        return [
            ...self::sourceMetadata(),
            'province' => self::provinceMetadata($province),
            'ward' => $ward,
        ];
    }

    public static function databaseCode(string $code): string
    {
        $code = trim($code);
        $withoutLeadingZeroes = ltrim($code, '0');

        return $withoutLeadingZeroes !== '' ? $withoutLeadingZeroes : $code;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $path = base_path(self::SOURCE_DATA_FILE);
        if (! is_file($path)) {
            throw new RuntimeException("Thanglequoc administrative unit data file is missing [{$path}].");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Thanglequoc administrative unit data file cannot be read [{$path}].");
        }

        $records = json_decode($contents, true);
        if (! is_array($records)) {
            throw new RuntimeException("Thanglequoc administrative unit data file is invalid JSON [{$path}].");
        }

        return $this->records = array_values($records);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sourceMetadata(): array
    {
        return [
            'source_repo' => self::SOURCE_REPO,
            'source_url' => self::SOURCE_URL,
            'source_data_url' => self::SOURCE_DATA_URL,
            'source_data_file' => self::SOURCE_DATA_FILE,
            'dataset_key' => self::DATASET_KEY,
            'release_version' => self::RELEASE_VERSION,
            'administrative_version' => self::ADMINISTRATIVE_VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $province
     * @return array<string, mixed>
     */
    private static function provinceMetadata(array $province): array
    {
        $metadata = $province;
        unset($metadata['Wards']);

        return $metadata;
    }
}
