<?php

namespace Cms\Core\Services\Enrichment;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Enrichment\EnrichmentSource;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EnrichmentSourceSyncService
{
    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<string, mixed> $defaults
     * @return array{synced: int, archived: int}
     */
    public function syncTargetSources(
        string $module,
        string $targetType,
        int|string|null $targetId,
        ?string $targetUuid,
        array $sources,
        array $defaults = [],
    ): array {
        if (! Schema::hasTable(CoreTableConstant::ENRICHMENT_SOURCES) || ($targetId === null && $targetUuid === null)) {
            return ['synced' => 0, 'archived' => 0];
        }

        $targetId = is_numeric($targetId) ? (int) $targetId : null;
        $targetUuid = $this->nullableString($targetUuid);
        $activeHashes = [];
        $synced = 0;

        foreach ($sources as $source) {
            $url = $this->normalizedUrl((string) ($source['source_url'] ?? $source['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $hash = hash('sha256', $url);
            $activeHashes[] = $hash;
            $sourceKind = $this->sourceKind($source['source_kind'] ?? $source['type'] ?? $defaults['source_kind'] ?? 'website');

            $identity = [
                'module' => $module,
                'target_type' => $targetType,
                'source_url_hash' => $hash,
            ];
            if ($targetId !== null) {
                $identity['target_id'] = $targetId;
            } else {
                $identity['target_uuid'] = $targetUuid;
            }

            EnrichmentSource::query()->updateOrCreate($identity, [
                'target_id' => $targetId,
                'target_uuid' => $targetUuid,
                'source_kind' => $sourceKind,
                'source_url' => $url,
                'adapter' => (string) ($source['adapter'] ?? $defaults['adapter'] ?? 'website'),
                'ai_schema_key' => $this->nullableString($source['ai_schema_key'] ?? $defaults['ai_schema_key'] ?? null),
                'status' => $this->sourceStatus($source['status'] ?? $defaults['status'] ?? 'active'),
                'default_min_confidence' => $this->nullableFloat($source['default_min_confidence'] ?? $defaults['default_min_confidence'] ?? null),
                'cadence_interval_days' => $this->boundedInt($source['cadence_interval_days'] ?? $defaults['cadence_interval_days'] ?? 7, 1, 60),
                'min_interval_days' => $this->boundedInt($source['min_interval_days'] ?? $defaults['min_interval_days'] ?? 1, 1, 60),
                'max_interval_days' => $this->boundedInt($source['max_interval_days'] ?? $defaults['max_interval_days'] ?? 60, 1, 60),
                'next_run_at' => $source['next_run_at'] ?? $defaults['next_run_at'] ?? null,
                'field_map' => $source['field_map'] ?? $defaults['field_map'] ?? null,
                'confidence_thresholds' => $source['confidence_thresholds'] ?? $defaults['confidence_thresholds'] ?? null,
                'confirmed_field_paths' => $source['confirmed_field_paths'] ?? $defaults['confirmed_field_paths'] ?? null,
                'settings' => $source['settings'] ?? $defaults['settings'] ?? null,
                'metadata' => [
                    ...((array) ($defaults['metadata'] ?? [])),
                    ...((array) ($source['metadata'] ?? [])),
                    'label' => $this->nullableString($source['label'] ?? null),
                    'source' => $this->nullableString($source['source'] ?? null),
                    'note' => $this->nullableString($source['note'] ?? null),
                    'auto_discover' => (bool) ($source['auto_discover'] ?? false),
                    'collected_at' => $this->nullableString($source['collected_at'] ?? null),
                ],
            ]);
            $synced++;
        }

        $query = EnrichmentSource::query()
            ->where('module', $module)
            ->where('target_type', $targetType);

        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        } elseif ($targetUuid !== null) {
            $query->where('target_uuid', $targetUuid);
        }

        $archived = (clone $query)
            ->when($activeHashes !== [], static fn ($archiveQuery) => $archiveQuery->whereNotIn('source_url_hash', array_values(array_unique($activeHashes))))
            ->when($activeHashes === [], static fn ($archiveQuery) => $archiveQuery->whereNotNull('id'))
            ->update(['status' => 'archived']);

        return ['synced' => $synced, 'archived' => (int) $archived];
    }

    private function normalizedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = Str::lower((string) $parts['host']);
        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        $path = rtrim($path, '/') ?: '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }

    private function sourceKind(mixed $value): string
    {
        $kind = Str::snake((string) $value);

        return $kind !== '' ? mb_substr($kind, 0, 80) : 'website';
    }

    private function sourceStatus(mixed $value): string
    {
        $status = Str::snake((string) $value);

        return in_array($status, ['active', 'paused', 'archived'], true) ? $status : 'active';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        return min($max, max($min, is_numeric($value) ? (int) $value : $min));
    }
}
