<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Drafts;

use Cms\Widget\Rendering\DataProviders\CanonicalJson;

/**
 * The SINGLE source of the repin `usage_fingerprint` identity.
 *
 * Two call sites depend on this value being byte-identical and MUST NOT
 * drift:
 *
 * - {@see UpdateInstanceAndRepinDraft::handle()} recomputes it under the
 *   Draft/Instance row locks and rejects the mutation
 *   (`WIDGET_USAGE_CHANGED`) unless the client echoed the exact same value.
 * - {@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutDraftController::show()}
 *   exposes it per referenced Instance on `GET /layouts/{uuid}/draft`
 *   (`WidgetLayoutDraftResource.instance_fingerprints`) so the admin canvas
 *   can echo it back on repin instead of recomputing a server-only formula.
 *
 * The token is OPAQUE (coordinator brief: "never trust or parse a client
 * value beyond equality") and is a `sha256` over exactly the DoD identity
 * set: this Draft's version/lock_version, the Instance's CURRENT version
 * NUMBER, and the sorted uuids of the Placements in THIS Draft that
 * reference ANY Version of that Instance ("matching Placements"). The
 * matching uuids are sorted here so both call sites feed an identical
 * canonical list regardless of the order their own query/collection yielded.
 */
final class WidgetDraftUsageFingerprint
{
    /**
     * @param list<string> $matchingPlacementUuids the uuids of this Draft's
     *        Placements referencing any Version of the Instance; sorted here.
     */
    public static function compute(
        int $draftVersion,
        int $draftLockVersion,
        ?int $instanceCurrentVersion,
        array $matchingPlacementUuids,
    ): string {
        sort($matchingPlacementUuids);

        return CanonicalJson::hash([
            'draft_version' => $draftVersion,
            'draft_lock_version' => $draftLockVersion,
            'instance_current_version' => $instanceCurrentVersion,
            'matching_placement_uuids' => $matchingPlacementUuids,
        ]);
    }
}
