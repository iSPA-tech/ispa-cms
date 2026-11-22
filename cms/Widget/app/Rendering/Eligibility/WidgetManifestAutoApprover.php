<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Security\WidgetProjectContextResolver;
use RuntimeException;

/**
 * Automatic manifest approval (Unified Widget Catalog Part 2): the per-site approval screen is
 * gone, so placing, previewing or publishing a widget approves its manifest on behalf of the
 * acting user. The materialize -> approve chain that the browser used to drive runs here.
 *
 * Rules:
 * - a blocked widget (Core `Widget::isBlocked()`) is never approved: {@see WidgetBlockedException};
 * - `pending` and `revoked` manifests are approved (decision: approval is dropped and the
 *   emergency block is the only stop, so a revoke from the compatibility route must not become a
 *   dead end with no screen left to undo it);
 * - every automatic approval writes the Core audit row `widget.auto_approved` with the actor and
 *   `{manifest_hash, theme_id, previous_state}`.
 */
final class WidgetManifestAutoApprover
{
    private const REASON = 'Approved automatically when the widget was used in Site Layout.';

    /** States the approval service lets move to `approved` (pending->approved, revoked->approved). */
    private const APPROVABLE_STATES = [
        WidgetManifestApprovalService::STATE_PENDING,
        WidgetManifestApprovalService::STATE_REVOKED,
    ];

    public function __construct(
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetManifestApprovalService $approvals,
        private readonly WidgetAuditRecorder $audit,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    /**
     * Materialize (when missing or drifted) and approve the CURRENT manifest of one widget/theme
     * pair. Returns its manifest hash.
     *
     * @param string|null $projectName Defaults to the server-owned project context.
     *
     * @throws WidgetBlockedException When the widget is blocked.
     * @throws RuntimeException When the pair cannot be materialized or approved.
     */
    public function ensureApproved(int $widgetId, int $themeId, int $actorUserId, ?string $projectName = null): string
    {
        $projectName ??= $this->projectContext->resolve()->projectName;
        $widget = $this->assertNotBlocked($widgetId);

        $result = $this->materializer->materialize($widgetId, $themeId, $projectName);
        $hash = $result->manifestRow['manifest_hash'] ?? null;

        if (!$result->isPersisted() || !is_string($hash) || $widget === null) {
            throw new RuntimeException("Widget manifest could not be materialized ({$result->status}).");
        }

        if (in_array($result->eligibilityState, self::APPROVABLE_STATES, true)) {
            $this->approve($projectName, $hash, (int) $result->attestationVersion, (string) $result->eligibilityState, $widget, $themeId, $actorUserId);
        }

        return $hash;
    }

    /**
     * Approve the manifest an Instance Version is pinned to, when it is pending or revoked (for
     * example a pin created before approval became automatic).
     *
     * @throws WidgetBlockedException When the pinned widget is blocked.
     */
    public function ensurePinnedApproved(WidgetInstanceVersion $version, int $actorUserId): void
    {
        $widget = $this->assertNotBlocked((int) $version->widget_id);

        if ($widget === null || $version->artifact_manifest_id === null) {
            return;
        }

        $manifest = WidgetArtifactManifestModel::query()
            ->where('project_name', $version->project_name)
            ->where('id', $version->artifact_manifest_id)
            ->first();

        if ($manifest === null || !in_array($manifest->eligibility_state, self::APPROVABLE_STATES, true)) {
            return;
        }

        $this->approve(
            (string) $manifest->project_name,
            (string) $manifest->manifest_hash,
            (int) $manifest->attestation_version,
            (string) $manifest->eligibility_state,
            $widget,
            (int) $version->widget_theme_id,
            $actorUserId,
        );
    }

    private function assertNotBlocked(int $widgetId): ?Widget
    {
        $widget = Widget::query()->find($widgetId);

        if ($widget !== null && $widget->isBlocked()) {
            throw new WidgetBlockedException($widgetId);
        }

        return $widget;
    }

    private function approve(string $projectName, string $hash, int $attestationVersion, string $previousState, Widget $widget, int $themeId, int $actorUserId): void
    {
        $outcome = $this->approvals->approve($projectName, $hash, $attestationVersion, $actorUserId, self::REASON);

        if ($outcome['outcome'] === WidgetManifestApprovalService::OUTCOME_SUCCESS) {
            $this->audit->record(WidgetAuditRecorder::ACTION_AUTO_APPROVED, $widget, $actorUserId, [
                'manifest_hash' => $hash,
                'theme_id' => $themeId,
                'previous_state' => $previousState,
            ]);

            return;
        }

        // Lost a race: fine when a concurrent request approved the same manifest.
        $state = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $hash)
            ->value('eligibility_state');

        if ($state !== WidgetManifestApprovalService::STATE_APPROVED) {
            throw new RuntimeException("Widget manifest could not be approved automatically ({$outcome['outcome']}).");
        }
    }
}
