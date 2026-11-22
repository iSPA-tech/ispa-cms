<?php

namespace Cms\Core\Services\PublishedData;

use InvalidArgumentException;
use JsonException;
use Illuminate\Support\Str;

final class PublishedDataIdentityService
{
    /**
     * @param array<string, mixed> $signals
     */
    public function candidateIdentityHash(array $signals): string
    {
        $identity = $this->firstUsableIdentity($signals);
        if ($identity === []) {
            throw new InvalidArgumentException('Published-data candidate requires external identity, source URL, canonical key, or content hash.');
        }

        return $this->hashParts($identity);
    }

    /**
     * @param array<string, mixed> $signals
     */
    public function clusterKey(array $signals): string
    {
        $identity = $this->firstUsableIdentity([
            'topic_fingerprint' => $signals['topic_fingerprint'] ?? null,
            'canonical_key' => $signals['canonical_key'] ?? null,
            'source_url' => $signals['source_url'] ?? null,
            'title' => $signals['title'] ?? null,
        ]);

        if ($identity === []) {
            throw new InvalidArgumentException('Published-data cluster requires topic fingerprint, canonical key, source URL, or title.');
        }

        return $this->hashParts($identity);
    }

    public function textHash(string $text): string
    {
        return hash('sha256', Str::lower($this->normalizeValue($text)));
    }

    public function nullableUrlHash(?string $url): ?string
    {
        $url = $this->normalizeValue($url);

        return $url === '' ? null : hash('sha256', $url);
    }

    /**
     * @param array<int|string, mixed> $parts
     */
    public function hashParts(array $parts): string
    {
        $normalized = [];
        foreach ($parts as $key => $value) {
            $value = $this->normalizeValue($value);
            if ($value === '') {
                continue;
            }

            $normalized[(string) $key] = $value;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Published-data identity hash requires at least one non-empty value.');
        }

        ksort($normalized);

        try {
            $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Published-data identity hash input is not JSON encodable.', previous: $exception);
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<string, string>
     */
    private function firstUsableIdentity(array $signals): array
    {
        $source = $this->normalizeValue($signals['source'] ?? null);
        $externalType = $this->normalizeValue($signals['external_type'] ?? null);
        $externalId = $this->normalizeValue($signals['external_id'] ?? null);
        if ($source !== '' && $externalType !== '' && $externalId !== '') {
            return [
                'kind' => 'external',
                'source' => $source,
                'external_type' => $externalType,
                'external_id' => $externalId,
            ];
        }

        foreach (['source_url', 'canonical_key', 'content_hash', 'topic_fingerprint', 'title'] as $key) {
            $value = $this->normalizeValue($signals[$key] ?? null);
            if ($value !== '') {
                return ['kind' => $key, $key => $value];
            }
        }

        return [];
    }

    private function normalizeValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = html_entity_decode(strip_tags((string) $value));
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';

        return Str::lower($value);
    }
}
