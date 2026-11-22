<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

/**
 * Read-only usage lookup backing the provider retention guard (plan Chunk 1
 * Task 11; docs/architecture/adr-widget-provider-lifecycle.md decision 4:
 * "A referenced key/version can never be unregistered while retained.").
 *
 * This slice is persistence-agnostic: the only implementations here are
 * {@see NullProviderUsageReader} (always-empty default) and in-memory test
 * doubles. The real database-backed reader, sourced from Layout
 * `registry_contracts` snapshots, lands in Task 13b after Task 13a creates
 * the Layout tables -- this interface is the seam that adapter will
 * implement without any change to {@see ProviderUsageGuard}.
 */
interface ProviderUsageReader
{
    /**
     * Whether any retained Draft/Release currently references this exact
     * provider key/version.
     */
    public function isReferenced(string $key, int $version): bool;

    /**
     * Every distinct {key, version} pair currently referenced by any
     * retained Draft/Release, regardless of whether that provider is still
     * registered. ProviderUsageGuard::health() diffs this list against the
     * live registry to report missing/deprecated usage (diagnostic only --
     * S-012 is rejected: this is never the deployment gate).
     *
     * @return list<array{key: string, version: int}>
     */
    public function referencedProviderVersions(): array;
}
