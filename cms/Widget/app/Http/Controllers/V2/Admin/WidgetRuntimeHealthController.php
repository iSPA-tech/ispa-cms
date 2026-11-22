<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Rendering\DataProviders\ProviderUsageGuard;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestDriftReader;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/admin/widget/health` (plan Chunk 2 Task 22; fixture `/health`
 * route entry, `response_resource: null`).
 *
 * A single aggregate, read-only diagnostic report combining every "through
 * safe gateways" health surface DoD 1199 names, none of them re-implemented
 * here -- this controller only calls existing, already-tested reporters and
 * shapes their output into one response body:
 *
 * - `registry_drift`: retained Draft/Release `registry_contracts` vs
 *   CURRENT registry state, the exact same comparison
 *   {@see \Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand}
 *   performs (via the same {@see WidgetRegistryCompatibilityChecker}), just
 *   exposed as a read-only HTTP report instead of a deploy-gating exit code.
 * - `provider_usage`: {@see ProviderUsageGuard::health()} -- missing/
 *   deprecated provider key/version pairs.
 * - `materialization`: {@see WidgetLegacyManifestDriftReader::report()} --
 *   drifted/current/incidents, `current[].eligibility_state` doubling as
 *   this report's eligibility-state surface (DoD 1199's own "eligibility
 *   state" item).
 * - `blob_manifest_integrity`: counts of Blob rows no Manifest references
 *   and Manifest rows no Instance Version references -- safe counts only,
 *   never a hash/path/key list.
 * - `emergency_block`: count of currently emergency-blocked Manifests --
 *   a safe count only, never `control_reason` text (architecture.md "no
 *   sensitive reason leakage publicly", the same discipline
 *   {@see \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlReader}
 *   already established for the render path).
 *
 * No raw Core query beyond what {@see WidgetLegacyManifestDriftReader}
 * already performs; every other section reads only this module's OWN
 * tables/registries.
 */
final class WidgetRuntimeHealthController
{
    public function __construct(
        private readonly WidgetRegistryCompatibilityChecker $registryChecker,
        private readonly ProviderUsageGuard $providerUsageGuard,
        private readonly WidgetLegacyManifestDriftReader $materializationDriftReader,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_instance.viewAny') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to view the Widget runtime health report.',
            );
        }

        return response()->json([
            'registry_drift' => $this->registryDriftIssues(),
            'provider_usage' => $this->providerUsageGuard->health(),
            'materialization' => $this->materializationDriftReader->report(),
            'blob_manifest_integrity' => [
                'unreferenced_blob_count' => WidgetArtifactBlob::query()->doesntHave('manifests')->count(),
                'unreferenced_manifest_count' => WidgetArtifactManifestModel::query()->doesntHave('instanceVersions')->count(),
            ],
            'emergency_block' => [
                'blocked_manifest_count' => WidgetArtifactManifestModel::query()->where('blocked', true)->count(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function registryDriftIssues(): array
    {
        $issues = [];

        $retained = WidgetLayoutVersion::query()
            ->whereIn('state', [WidgetLayoutVersion::STATE_DRAFT, WidgetLayoutVersion::STATE_RELEASE])
            ->get();

        foreach ($retained as $row) {
            $contracts = is_array($row->registry_contracts) ? $row->registry_contracts : [];

            foreach ($this->registryChecker->compare($contracts) as $breach) {
                $issues[] = "layout_version #{$row->id} (layout_id={$row->layout_id}, state={$row->state}): {$breach}";
            }
        }

        return $issues;
    }
}
