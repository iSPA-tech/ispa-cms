<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable result of a section export: rows, skipped counts by reason and the secrets the target must supply.
 */
final class SectionExport
{
    /**
     * @param list<array<string, mixed>> $rows            Exported rows (never numeric ids).
     * @param array<string, int>         $skipped         Reason => number of skipped rows.
     * @param list<string>               $requiredSecrets Keys of skipped secret rows.
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $skipped = [],
        public readonly array $requiredSecrets = [],
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: array<string, int>, required_secrets: list<string>}
     */
    public function toArray(): array
    {
        return ['rows' => $this->rows, 'skipped' => $this->skipped, 'required_secrets' => $this->requiredSecrets];
    }
}
