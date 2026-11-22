<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Safe, actionable usage summary for a legacy Widget Definition/Theme row
 * that is currently referenced by at least one retained `cms/Widget`
 * Instance Version (plan Chunk 2 Task 16; architecture.md "Foreign keys
 * and deletion": "Deleting or archiving a Widget Definition or Theme
 * referenced by any retained Instance Version returns 409 with usage
 * summary, whether or not that Version is currently placed").
 *
 * Returned by {@see \App\Contracts\Widget\WidgetMutationGuard::usageSummaryFor()}.
 * `usageFingerprint` is an OPAQUE, non-reconstructable token derived from
 * the current retained-reference set -- callers must never treat it (or
 * `retainedVersionCount`) as a concurrency-control/CAS value (plan
 * checklist: "Return an opaque usage_fingerprint with usage projection; do
 * not use count as concurrency control"). It carries no HTML, values, or
 * any other sensitive content -- only enough for a client to render an
 * actionable "N configured places reference this; recovery: ..." message.
 */
final readonly class WidgetMutationUsageSummary
{
    public function __construct(
        public string $table,
        public int $id,
        public int $retainedVersionCount,
        public string $usageFingerprint,
    ) {
    }
}
