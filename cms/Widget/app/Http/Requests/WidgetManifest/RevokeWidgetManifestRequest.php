<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetManifest;

/**
 * `DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval`
 * request shape (plan Chunk 2 Task 15b;
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json approval
 * route entry).
 *
 * Symmetric to {@see ApproveWidgetManifestRequest} -- same frozen payload,
 * same permission (`widget_template.approve`; revocation uses the SAME
 * permission as approval per the fixture, there is no separate "revoke"
 * permission), all inherited from {@see AbstractWidgetManifestApprovalRequest}.
 * Feeds the approved->revoked transition
 * ({@see \Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService::revoke()}),
 * a future-promotion gate that never changes an active public Release's own
 * eligibility snapshot.
 */
final class RevokeWidgetManifestRequest extends AbstractWidgetManifestApprovalRequest
{
    protected function permissionDeniedMessage(): string
    {
        return 'You do not have permission to revoke Widget manifests.';
    }

    protected function validationFailedMessage(): string
    {
        return 'The revocation request failed validation.';
    }
}
