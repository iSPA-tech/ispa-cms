<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use Cms\Widget\Models\WidgetInstanceVersion;

/**
 * Real database implementation of {@see ProviderUsageReader} (plan Chunk 2
 * Task 13b; bound in
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} after Task 13a's
 * `widget_instance_versions` table exists).
 *
 * Source of truth: retained `widget_instance_versions.provider_refs`
 * (JSON, cast to array per the 13a model). Instance Versions are immutable
 * and never deleted once inserted (no `UPDATED_AT`, no destructive cascade
 * anywhere in this schema -- plan "no cascade that can destroy retained
 * history"), so every row this class reads is by definition "retained" --
 * there is no separate soft-delete or archival flag to additionally filter
 * on for this table.
 *
 * DISCLOSED SHAPE ASSUMPTION: no Instance-Version write path exists yet
 * (Task 16+), so this reader documents rather than cites the expected
 * `provider_refs` entry shape: `{key: string, version: int, ...}`, where
 * `version` matches the LIVE REGISTRY's integer version -- the same
 * `int $version` this interface's own methods type-hint, and DISTINCT from
 * {@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest}'s
 * `providerContracts` (`{key, version: string label, provider_contract_hash}`),
 * which identifies an executable CONTRACT at the Manifest/artifact level,
 * not a registry reference. `version` is read defensively via `is_numeric()`
 * so this reader degrades gracefully (skips the entry) rather than fatally
 * erroring a health scan if a future write path's shape differs from this
 * assumption -- Task 16+ is the actual proof owner for the write side.
 *
 * Deliberately scans and decodes in PHP rather than pushing a JSON
 * containment query into SQL: MySQL (`JSON_CONTAINS`), PostgreSQL (`@>`
 * jsonb operators), and SQLite (`json_each`) have no shared portable
 * syntax, and this table is expected to stay small at MVP scale -- a
 * "primitives only" implementation favors portability over a
 * per-engine-branched query here.
 */
final class DatabaseProviderUsageReader implements ProviderUsageReader
{
    public function isReferenced(string $key, int $version): bool
    {
        foreach ($this->referencedProviderVersions() as $usage) {
            if ($usage['key'] === $key && $usage['version'] === $version) {
                return true;
            }
        }

        return false;
    }

    public function referencedProviderVersions(): array
    {
        $distinct = [];

        foreach (WidgetInstanceVersion::query()->select('provider_refs')->cursor() as $row) {
            foreach ((array) $row->provider_refs as $ref) {
                $key = (string) ($ref['key'] ?? '');
                $version = $ref['version'] ?? null;

                if ($key === '' || !is_numeric($version)) {
                    continue;
                }

                $intVersion = (int) $version;
                $distinct[$key . '@' . $intVersion] = ['key' => $key, 'version' => $intVersion];
            }
        }

        return array_values($distinct);
    }
}
