<?php

namespace Cms\Core\Services\Seeders;

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SeedFingerprintService
{
    /**
     * @param callable(): bool $sanityCheck
     */
    public function shouldSkip(
        string $key,
        string $seederClass,
        string $sourceHash,
        int $sourceCount,
        int $seederVersion,
        callable $sanityCheck
    ): bool {
        if (! $this->isAvailable()) {
            return false;
        }

        $record = DB::table(CoreTableConstant::SEED_FINGERPRINTS)
            ->where('fingerprint_key', $key)
            ->first();

        if ($record === null) {
            return false;
        }

        if (
            (string) $record->status !== 'completed'
            || (string) $record->seeder_class !== $seederClass
            || (string) $record->source_hash !== $sourceHash
            || (int) $record->source_count !== $sourceCount
            || (int) $record->seeder_version !== $seederVersion
        ) {
            return false;
        }

        return $sanityCheck();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markCompleted(
        string $key,
        string $seederClass,
        string $sourceHash,
        int $sourceCount,
        int $seederVersion,
        array $metadata = []
    ): void {
        if (! $this->isAvailable()) {
            return;
        }

        $now = now();

        DB::table(CoreTableConstant::SEED_FINGERPRINTS)->updateOrInsert(
            ['fingerprint_key' => $key],
            [
                'seeder_class' => $seederClass,
                'source_hash' => $sourceHash,
                'source_count' => $sourceCount,
                'seeder_version' => $seederVersion,
                'status' => 'completed',
                'metadata' => $metadata === []
                    ? null
                    : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * @param array<int|string, string> $relativePaths
     * @param array<string, mixed> $metadata
     */
    public function sourceHash(array $relativePaths, array $metadata = []): string
    {
        $payload = [
            'metadata' => $metadata,
            'files' => [],
        ];

        foreach ($relativePaths as $label => $relativePath) {
            $path = base_path($relativePath);
            if (! is_file($path)) {
                throw new RuntimeException("Seed source file is missing [{$path}].");
            }

            $payload['files'][(string) $label] = [
                'path' => $relativePath,
                'sha256' => hash_file('sha256', $path),
            ];
        }

        ksort($payload['files']);
        ksort($payload['metadata']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function isAvailable(): bool
    {
        return Schema::hasTable(CoreTableConstant::SEED_FINGERPRINTS);
    }
}
