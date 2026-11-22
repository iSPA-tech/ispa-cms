<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetManifest;

/**
 * `POST /api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval`
 * request shape (plan Chunk 2 Task 15b;
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json approval
 * route entry).
 *
 * Frozen payload `{expected_attestation_version, reason}` (`reason`
 * optional -- architecture.md's "mandatory reason" language is scoped to the
 * separate emergency runtime-block endpoints, not this eligibility-approval
 * endpoint). This same request drives BOTH the pending->approved and
 * revoked->approved (explicit reapproval) transitions --
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService::approve()}
 * decides which FROM-state is actually current. See
 * {@see AbstractWidgetManifestApprovalRequest} for the shared
 * permission/rules/403-before-422 machinery and
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService}'s
 * class docblock for the full disclosed reading of the checklist's
 * requirement list against the frozen request-field contract.
 */
final class ApproveWidgetManifestRequest extends AbstractWidgetManifestApprovalRequest
{
    protected function permissionDeniedMessage(): string
    {
        return 'You do not have permission to approve Widget manifests.';
    }

    protected function validationFailedMessage(): string
    {
        return 'The approval request failed validation.';
    }
}
