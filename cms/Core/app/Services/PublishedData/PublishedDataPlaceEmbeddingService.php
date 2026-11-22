<?php

namespace Cms\Core\Services\PublishedData;

use Cms\Core\Agents\Contracts\AgenticEmbeddingProviderInterface;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\PublishedData\PublishedDataCandidate;
use Cms\Core\Models\PublishedData\PublishedDataEmbedding;
use Cms\Core\Models\PublishedData\PublishedDataSourceCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class PublishedDataPlaceEmbeddingService
{
    public const PURPOSE_PLACE_DEDUPE = 'place_dedupe';

    /**
     * @var array<string, list<float>>
     */
    private array $embeddingCache = [];

    public function __construct(
        private readonly AgenticEmbeddingProviderInterface $embeddingProvider,
        private readonly PublishedDataSemanticMatcher $semanticMatcher,
    ) {
    }

    public function ready(): bool
    {
        return (bool) $this->configValue('enabled', false)
            && Schema::hasTable(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS);
    }

    /**
     * @param array<string, mixed> $placePayload
     * @return list<float>
     */
    public function embeddingVectorForPlacePayload(array $placePayload): array
    {
        if (! $this->ready()) {
            return [];
        }

        $text = $this->textFromPlacePayload($placePayload);
        if ($text === '') {
            return [];
        }

        return $this->embeddingForText($text, $this->model());
    }

    /**
     * @param array<string, mixed> $placePayload
     * @param list<float>|null $vector
     */
    public function upsertCandidateEmbedding(
        PublishedDataCandidate $candidate,
        array $placePayload,
        ?array $vector = null,
    ): ?PublishedDataEmbedding {
        return $this->upsertOwnerEmbedding(
            module: (string) $candidate->module,
            profileSlug: (string) $candidate->profile_slug,
            ownerType: PublishedDataCandidate::class,
            ownerUuid: (string) $candidate->uuid,
            placePayload: $placePayload,
            languageCode: (string) ($candidate->language_code ?: 'vi'),
            candidate: $candidate,
            sourceCluster: $candidate->sourceCluster,
            vector: $vector,
            metadata: [
                'candidate_type' => $candidate->candidate_type,
                'target_type' => $candidate->target_type,
                'source' => $candidate->source,
                'external_type' => $candidate->external_type,
                'external_id' => $candidate->external_id,
                'canonical_target_type' => $candidate->canonical_target_type,
                'canonical_target_uuid' => $candidate->canonical_target_uuid,
            ],
        );
    }

    /**
     * @param array<string, mixed> $placePayload
     * @param list<float>|null $vector
     * @param array<string, mixed> $metadata
     */
    public function upsertOwnerEmbedding(
        string $module,
        string $profileSlug,
        string $ownerType,
        string $ownerUuid,
        array $placePayload,
        string $languageCode = 'vi',
        ?PublishedDataCandidate $candidate = null,
        ?PublishedDataSourceCluster $sourceCluster = null,
        ?array $vector = null,
        array $metadata = [],
    ): ?PublishedDataEmbedding {
        if (! $this->ready() || $ownerType === '' || $ownerUuid === '') {
            return null;
        }

        $text = $this->textFromPlacePayload($placePayload);
        if ($text === '') {
            return null;
        }

        $vector = $this->semanticMatcher->sanitizeVector($vector ?? $this->embeddingForText($text, $this->model()));
        if ($vector === []) {
            return null;
        }

        $row = PublishedDataEmbedding::query()->firstOrNew([
            'owner_type' => $ownerType,
            'owner_uuid' => $ownerUuid,
            'embedding_model' => $this->model(),
            'embedding_model_version' => $this->modelVersion(),
        ]);
        $row->fill([
            'uuid' => $row->uuid ?: (string) Str::uuid(),
            'source_cluster_id' => $sourceCluster?->id ?? $candidate?->source_cluster_id,
            'candidate_id' => $candidate?->id,
            'module' => $module,
            'profile_slug' => $profileSlug,
            'embedding_purpose' => self::PURPOSE_PLACE_DEDUPE,
            'language_code' => $languageCode ?: 'vi',
            'text_hash' => $this->textHash($text),
            'embedding_dimensions' => count($vector),
            'embedding_json' => $vector,
            'metadata' => array_merge($this->metadataFromPlacePayload($placePayload), $metadata),
        ])->save();

        $this->writePgVector($row, $vector);

        return $row;
    }

    /**
     * @param array<string, mixed> $placePayload
     * @param list<float>|null $vector
     * @param list<string> $ownerTypes
     * @return array{embedding: PublishedDataEmbedding, score: float, classification: string, owner_type: ?string, owner_uuid: ?string, candidate_id: ?int, metadata: array<string, mixed>}|null
     */
    public function bestMatchForPlacePayload(
        string $module,
        string $profileSlug,
        array $placePayload,
        string $languageCode = 'vi',
        array $ownerTypes = [],
        ?string $excludeOwnerType = null,
        ?string $excludeOwnerUuid = null,
        ?array $vector = null,
    ): ?array {
        if (! $this->ready()) {
            return null;
        }

        $vector = $this->semanticMatcher->sanitizeVector($vector ?? $this->embeddingVectorForPlacePayload($placePayload));
        if ($vector === []) {
            return null;
        }

        $rows = $this->pgVectorRows(
            vector: $vector,
            module: $module,
            profileSlug: $profileSlug,
            languageCode: $languageCode ?: 'vi',
            ownerTypes: $ownerTypes,
            excludeOwnerType: $excludeOwnerType,
            excludeOwnerUuid: $excludeOwnerUuid,
        ) ?? $this->jsonVectorRows(
            module: $module,
            profileSlug: $profileSlug,
            languageCode: $languageCode ?: 'vi',
            ownerTypes: $ownerTypes,
            excludeOwnerType: $excludeOwnerType,
            excludeOwnerUuid: $excludeOwnerUuid,
        );

        $best = null;
        foreach ($rows as $row) {
            $score = isset($row->semantic_score)
                ? (float) $row->semantic_score
                : $this->semanticMatcher->cosineSimilarity($vector, (array) $row->embedding_json);

            if ($best === null || $score > $best['score']) {
                $classification = $this->semanticMatcher->classifyScore($score, $profileSlug);
                $best = [
                    'embedding' => $row,
                    'score' => round($score, 6),
                    'classification' => $classification,
                    'owner_type' => $row->owner_type,
                    'owner_uuid' => $row->owner_uuid,
                    'candidate_id' => $row->candidate_id,
                    'metadata' => (array) ($row->metadata ?? []),
                ];
            }
        }

        if ($best === null || $best['classification'] === PublishedDataSemanticMatcher::CLASS_NEW_CANDIDATE) {
            return null;
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $placePayload
     */
    public function textFromPlacePayload(array $placePayload): string
    {
        $parts = [
            $placePayload['name'] ?? null,
            $placePayload['category'] ?? null,
            $placePayload['provider_category'] ?? null,
            $placePayload['address'] ?? null,
            $placePayload['phone'] ?? null,
            $placePayload['website'] ?? null,
            $placePayload['source_url'] ?? null,
            $placePayload['latitude'] ?? null,
            $placePayload['longitude'] ?? null,
        ];

        foreach (['types', 'tags', 'services'] as $key) {
            $value = $placePayload[$key] ?? null;
            if (is_array($value)) {
                $parts[] = implode(' ', array_map(static fn (mixed $item): string => (string) $item, $value));
            }
        }

        $text = html_entity_decode(strip_tags(implode("\n", array_map(
            static fn (mixed $part): string => trim((string) $part),
            array_filter($parts, static fn (mixed $part): bool => $part !== null && $part !== ''),
        ))));
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';

        return mb_substr($text, 0, max(200, (int) $this->configValue('max_text_chars', 2000)));
    }

    /**
     * @return list<float>
     */
    private function embeddingForText(string $text, string $model): array
    {
        $cacheKey = $model . ':' . $this->textHash($text);
        if (isset($this->embeddingCache[$cacheKey])) {
            return $this->embeddingCache[$cacheKey];
        }

        try {
            $vector = $this->semanticMatcher->sanitizeVector($this->embeddingProvider->createEmbedding($text, $model));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $this->embeddingCache[$cacheKey] = $vector;

        return $vector;
    }

    /**
     * @param list<float> $vector
     * @param list<string> $ownerTypes
     * @return Collection<int, PublishedDataEmbedding>|null
     */
    private function pgVectorRows(
        array $vector,
        string $module,
        string $profileSlug,
        string $languageCode,
        array $ownerTypes,
        ?string $excludeOwnerType,
        ?string $excludeOwnerUuid,
    ): ?Collection {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasColumn(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS, 'embedding_vector')
            || count($vector) !== $this->dimensions()) {
            return null;
        }

        $literal = $this->vectorLiteral($vector);

        try {
            $query = $this->baseMatchQuery($module, $profileSlug, $languageCode, $ownerTypes, $excludeOwnerType, $excludeOwnerUuid)
                ->whereNotNull('embedding_vector')
                ->select(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS . '.*')
                ->selectRaw('1 - (embedding_vector <=> ?::vector) as semantic_score', [$literal])
                ->orderByRaw('embedding_vector <=> ?::vector', [$literal])
                ->limit($this->scanLimit());

            return $query->get();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param list<string> $ownerTypes
     * @return Collection<int, PublishedDataEmbedding>
     */
    private function jsonVectorRows(
        string $module,
        string $profileSlug,
        string $languageCode,
        array $ownerTypes,
        ?string $excludeOwnerType,
        ?string $excludeOwnerUuid,
    ): Collection {
        return $this->baseMatchQuery($module, $profileSlug, $languageCode, $ownerTypes, $excludeOwnerType, $excludeOwnerUuid)
            ->latest('updated_at')
            ->limit($this->scanLimit())
            ->get();
    }

    /**
     * @param list<string> $ownerTypes
     */
    private function baseMatchQuery(
        string $module,
        string $profileSlug,
        string $languageCode,
        array $ownerTypes,
        ?string $excludeOwnerType,
        ?string $excludeOwnerUuid,
    ): \Illuminate\Database\Eloquent\Builder {
        $query = PublishedDataEmbedding::query()
            ->where('module', $module)
            ->where('profile_slug', $profileSlug)
            ->where('language_code', $languageCode)
            ->where('embedding_purpose', self::PURPOSE_PLACE_DEDUPE)
            ->where('embedding_model', $this->model())
            ->where('embedding_model_version', $this->modelVersion());

        if ($ownerTypes !== []) {
            $query->whereIn('owner_type', $ownerTypes);
        }

        if ($excludeOwnerType !== null && $excludeOwnerUuid !== null) {
            $query->where(static function ($query) use ($excludeOwnerType, $excludeOwnerUuid): void {
                $query->where('owner_type', '!=', $excludeOwnerType)
                    ->orWhere('owner_uuid', '!=', $excludeOwnerUuid);
            });
        }

        return $query;
    }

    /**
     * @param list<float> $vector
     */
    private function writePgVector(PublishedDataEmbedding $row, array $vector): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasColumn(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS, 'embedding_vector')
            || count($vector) !== $this->dimensions()) {
            return;
        }

        try {
            DB::table(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS)
                ->where('id', $row->id)
                ->update([
                    'embedding_vector' => DB::raw("'" . $this->vectorLiteral($vector) . "'::vector"),
                ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param list<float> $vector
     */
    private function vectorLiteral(array $vector): string
    {
        $values = array_map(function (float $value): string {
            $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

            return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
        }, $vector);

        return '[' . implode(',', $values) . ']';
    }

    /**
     * @param array<string, mixed> $placePayload
     * @return array<string, mixed>
     */
    private function metadataFromPlacePayload(array $placePayload): array
    {
        return [
            'name' => $this->nullableString($placePayload['name'] ?? null),
            'category' => $this->nullableString($placePayload['category'] ?? null),
            'provider_category' => $this->nullableString($placePayload['provider_category'] ?? null),
            'address' => $this->nullableString($placePayload['address'] ?? null),
            'phone' => $this->nullableString($placePayload['phone'] ?? null),
            'website' => $this->nullableString($placePayload['website'] ?? null),
            'source_url' => $this->nullableString($placePayload['source_url'] ?? null),
            'latitude' => $placePayload['latitude'] ?? null,
            'longitude' => $placePayload['longitude'] ?? null,
        ];
    }

    private function model(): string
    {
        return (string) $this->configValue('model', 'text-embedding-3-small');
    }

    private function modelVersion(): string
    {
        return (string) $this->configValue('model_version', 'default');
    }

    private function dimensions(): int
    {
        return max(1, (int) $this->configValue('dimensions', 1536));
    }

    private function scanLimit(): int
    {
        return max(1, (int) $this->configValue('scan_limit', 500));
    }

    private function textHash(string $text): string
    {
        return hash('sha256', Str::lower($text));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function configValue(string $key, mixed $default): mixed
    {
        $shortKey = "published_data.place_embeddings.{$key}";
        if (config()->has($shortKey)) {
            return config($shortKey, $default);
        }

        return config("core.published_data.place_embeddings.{$key}", $default);
    }
}
