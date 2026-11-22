<?php

namespace Cms\Core\Contracts;

/**
 * A module-owned importer that creates sample content from a starter-pack data file.
 *
 * Importers are create-only and idempotent: rows that already exist are skipped, never updated.
 */
interface StarterContentImporter
{
    /**
     * Stable importer id, for example `helpdesk.kb`.
     */
    public function id(): string;

    /**
     * Name of the module that owns this importer.
     */
    public function module(): string;

    /**
     * Check the decoded pack data.
     *
     * @param array<string, mixed> $data
     * @return list<string> Human-readable English errors; empty when the data is valid.
     */
    public function validate(array $data): array;

    /**
     * Count what an import would create right now (rows that already exist are not counted).
     *
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function plan(array $data): array;

    /**
     * Create the missing content.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options Importer specific options.
     * @return array{created: array<string, int>, skipped: array<string, int>}
     */
    public function import(array $data, array $options = []): array;

    /**
     * Read the content this importer owns from the current site, in the same shape `import()` accepts.
     *
     * The result must be plain data: no secrets, personal data, counters or draft content. An importer that cannot
     * export returns an empty array, which tells the pack exporter to leave this importer out of the pack.
     *
     * @param array<string, mixed> $options Importer specific options.
     * @return array<string, mixed> Importable data, or an empty array when nothing can be exported.
     */
    public function export(array $options): array;
}
