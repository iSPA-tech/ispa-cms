<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\PreviewWidgetLayoutRequest;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Preview\WidgetPreviewMintResult;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/preview-sessions` (plan Chunk 2
 * Task 19; fixture preview-sessions route entry).
 *
 * Thin HTTP layer mirroring {@see WidgetLayoutPublishController}/
 * {@see WidgetLayoutDraftController}'s established shape: request-SHAPE
 * validation is {@see PreviewWidgetLayoutRequest}'s job, project resolution
 * is {@see WidgetProjectContextResolver}'s job, every domain
 * validation/manifest-eligibility/encryption/token-mint concern is entirely
 * {@see WidgetPreviewSessionService}'s job. This controller only wires them
 * together and maps the service's outcome onto the HTTP contract.
 *
 * STATUS-CODE CONFORMANCE (coordinator-adjudicated standing rule: the
 * frozen fixture wins; a route returns ONLY statuses it declares): the
 * fixture declares this route's `statuses` as `[201, 403, 404, 422]` only
 * -- no 409, no 500. Every manifest-ineligibility outcome at mint
 * (pending, revoked, blocked) therefore maps to the enum's 422 tier,
 * {@see WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED}
 * ("manifest not in an approved/eligible state", established in Tasks
 * 15a/15b), with the precise sub-reason carried in the envelope `message`.
 * The 409 codes `WIDGET_MANIFEST_REVOKED`/`WIDGET_MANIFEST_RUNTIME_BLOCKED`
 * are deliberately NOT used here -- they would ship a status this route's
 * own machine contract (consumed verbatim by Chunk 3 base-admin) does not
 * permit. This supersedes the earlier "fixture only checks internal
 * consistency" reading (the Task-20 201-vs-fixture precedent that briefly
 * justified it was itself reversed by the coordinator, publish conformed
 * 201->200). No new code is added; the fixture stays byte-identical. The
 * after-consume manifest recheck on the PUBLIC render path
 * ({@see \Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession}) is a
 * separate, non-admin-API surface (it renders nothing / mutates no
 * pointer) and is intentionally left as built.
 */
final class WidgetLayoutPreviewController
{
    public function __construct(
        private readonly WidgetPreviewSessionService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function mint(PreviewWidgetLayoutRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->mint(
            $project->projectName,
            $uuid,
            $request->user()?->id,
            $request->overlay(),
            (string) $request->input('locale'),
            (string) $request->input('target_type') === '' ? '' : (string) $request->input('template_context'),
            (string) $request->input('target_type'),
            (string) $request->input('target_id'),
            $request->input('viewport') === null ? null : (string) $request->input('viewport'),
            $request->input('editor_nonce') === null ? null : (string) $request->input('editor_nonce'),
        );

        return match ($result->status) {
            WidgetPreviewMintResult::STATUS_SUCCESS => response()->json($this->successPayload($result), 201),
            WidgetPreviewMintResult::STATUS_LAYOUT_NOT_FOUND => $this->genericNotFound('Widget site layout not found.'),
            WidgetPreviewMintResult::STATUS_TARGET_NOT_FOUND => $this->genericNotFound('One of the requested targets was not found.'),
            WidgetPreviewMintResult::STATUS_TARGET_DENIED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to reference one of the requested targets.',
            ),
            WidgetPreviewMintResult::STATUS_VALIDATION_FAILED => $this->genericUnprocessable((string) $result->message),
            // Every manifest-ineligibility outcome at MINT maps to the SAME
            // 422 WIDGET_MANIFEST_APPROVAL_REQUIRED. The frozen fixture
            // declares this route's statuses as [201, 403, 404, 422] ONLY --
            // no 409 -- so a 409 code (WIDGET_MANIFEST_REVOKED /
            // WIDGET_MANIFEST_RUNTIME_BLOCKED) would ship a status this
            // route's own machine contract does not permit (Chunk 3
            // base-admin consumes this fixture verbatim). 422
            // WIDGET_MANIFEST_APPROVAL_REQUIRED is the enum's "manifest not
            // in an approved/eligible state" tier (established in Tasks
            // 15a/15b); the precise sub-reason (pending vs revoked vs
            // blocked) rides in the envelope `message`, not the code. This
            // supersedes the earlier Task-20 "fixture only checks internal
            // consistency" reading, reversed by the coordinator (standing
            // rule: the frozen fixture wins; a route returns only statuses
            // it declares). The after-consume recheck on the public render
            // path (ResolveWidgetPreviewSession) is a DIFFERENT, non-admin-
            // API surface and is intentionally left as built.
            WidgetPreviewMintResult::STATUS_MANIFEST_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by this overlay is pending approval.',
            ),
            WidgetPreviewMintResult::STATUS_MANIFEST_REVOKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by this overlay has been revoked and is no longer eligible.',
            ),
            WidgetPreviewMintResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by this overlay is currently emergency-blocked and is not eligible.',
            ),
            // Defensive/unreachable: WidgetPreviewMintResult's status set is
            // closed and every member is handled above. This fallback must
            // still emit a DECLARED status ([201,403,404,422]) -- never the
            // 500 an integrity code would map to -- so it renders a minimal
            // 422, consistent with the STATUS_VALIDATION_FAILED arm.
            default => $this->genericUnprocessable('An unexpected error occurred while creating the preview session.'),
        };
    }

    /**
     * No generic "resource not found" code exists in the frozen 26-code
     * dictionary -- mirrors {@see WidgetLayoutDraftController::genericNotFound()}'s
     * own disclosed minimal-body precedent.
     */
    private function genericNotFound(string $message): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => $message,
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    /**
     * No code in the frozen 26-code dictionary fits "the submitted overlay
     * document is domain-invalid" -- mirrors
     * {@see WidgetLayoutDraftController::validationFailed()}'s own
     * disclosed minimal-body precedent.
     */
    private function genericUnprocessable(string $message): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => $message,
            'correlation_id' => (string) Str::uuid(),
        ], 422);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(WidgetPreviewMintResult $result): array
    {
        $context = $result->context;

        return [
            'preview_url' => $result->previewUrl,
            'expires_at' => $result->expiresAt?->format(DATE_ATOM),
            'fingerprint' => $result->fingerprint,
            'diagnostics' => [
                'project_name' => $context?->projectName,
                'layout_uuid' => $context?->layoutUuid,
                'locale' => $context?->locale,
                // Untargeted home-page preview: no Template Context and no target (null, not "").
                'template_context' => $this->nullIfEmpty($context?->templateContext),
                'target_type' => $this->nullIfEmpty($context?->targetType),
                'target_id' => $this->nullIfEmpty($context?->targetId),
                'canonical_route' => [
                    'name' => $context?->canonicalRouteName,
                    'parameters' => $context?->canonicalRouteParameters,
                ],
                'release_baseline' => $context?->releaseBaseline,
                'viewport' => $context?->viewport,
            ],
        ];
    }
}
