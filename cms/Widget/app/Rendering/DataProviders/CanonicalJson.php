<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

/**
 * Disclosed deviation from Chunk 1 Task 11's literal allowed_paths (plan
 * lines 614-644): `provider_contract_hash` must canonicalize into a
 * deterministic, insertion-order-independent JSON string (frozen contract:
 * "semantic-hash stability/canonical ordering"). That exact recursive-ksort
 * + `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES` discipline already has THREE
 * independent private copies in this codebase:
 * `Cms\Core\Services\Widget\LegacyWidgetArtifactGenerationManifest::toCanonicalJson()`,
 * `Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer`'s
 * private `canonicalJson()` (Task 9c), and
 * `Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder`'s private
 * `canonicalJson()` (Task 10). Rather than adding a FOURTH private copy,
 * this task extracts the discipline into one small module-internal helper
 * -- not listed in Task 11's allowed_paths, so disclosed here per the same
 * deviation-recording precedent as
 * `App\Contracts\Widget\DTO\AuthorizedProjectContext` (Task 7). A future
 * task (13b or a dedicated cleanup) should consider consolidating the three
 * prior private copies onto this helper rather than adding a fifth.
 */
final class CanonicalJson
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode(
            self::ksortRecursive($data),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', self::encode($data));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function ksortRecursive(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::ksortRecursive($value);
            }
        }
        unset($value);

        ksort($data);

        return $data;
    }
}
