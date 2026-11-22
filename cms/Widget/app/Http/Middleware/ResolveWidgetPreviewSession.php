<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Middleware;

use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Preview\WidgetPreviewConsumeResult;
use Cms\Widget\Preview\WidgetPreviewContext;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-aware second stage of the Widget signed preview session transport
 * (plan Chunk 2 Task 19, DoD 1108; architecture.md "Public preview
 * request"; docs/architecture/adr-widget-preview-session.md decision 6).
 *
 * Pushed onto the root `web` middleware group by
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} -- NEVER
 * registered per-route, so ANY existing/future public `web` route becomes
 * preview-capable with zero opt-in wiring (architecture.md: "the existing
 * public route/controller/layout" must execute, never a parallel renderer).
 * By the time ANY `web`-group middleware runs, Laravel has already matched
 * the route (`$request->route()` is populated), so this class can validate
 * "route identity" as required.
 *
 * A complete NO-OP (zero Redis calls, zero container writes) unless the
 * root {@see PrepareAnonymousWidgetPreviewRequest} already marked this
 * request as a structurally-valid candidate -- ordinary public traffic
 * pays no cost.
 *
 * On success, atomically consumes the token (via
 * {@see WidgetPreviewSessionService::consume()}, which itself re-validates
 * route identity/project/manifest eligibility -- DoD 1106, 1111) and
 * installs the decrypted {@see WidgetPreviewContext} as request-scoped
 * container state for the remainder of this ONE request. Every binding is
 * cleared in `finally` on BOTH the success and exception path (DoD 1108) --
 * required for Octane/long-lived-worker safety even though production is
 * currently proven php-fpm (request-per-process), matching the ADR's own
 * "must not silently regress if the worker model changes" requirement.
 *
 * A failed consume (expired/replayed/tampered/mismatched/revoked) renders
 * the SAME frozen {@see WidgetApiErrorResponse} JSON envelope every other
 * Widget module surface uses, deliberately never falling through to render
 * the real public page under a rejected preview attempt -- "fail without
 * revealing target metadata" (architecture.md).
 */
final class ResolveWidgetPreviewSession
{
    public function __construct(
        private readonly WidgetPreviewSessionService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(PrepareAnonymousWidgetPreviewRequest::CANDIDATE_ATTRIBUTE) !== true) {
            return $next($request);
        }

        $token = (string) $request->query(PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER, '');
        $route = $request->route();
        $routeName = $route?->getName() ?? '';
        $routeParameters = $route?->parameters() ?? [];

        $project = $this->projectContext->resolve();

        $result = $this->service->consume($token, $project->projectName, $routeName, $routeParameters);

        if (!$result->isSuccess() || $result->context === null) {
            return $this->failureResponse($result);
        }

        app()->instance(WidgetPreviewContext::class, $result->context);

        // Editor mode marker for the root response-stage middleware
        // (PrepareAnonymousWidgetPreviewRequest): request attributes survive
        // the `finally` container cleanup below, which runs BEFORE the root
        // middleware's response pass. A plain string attribute keeps the
        // root class free of Widget-module imports (D-040).
        if ($result->context->editorNonce !== null) {
            $request->attributes->set(
                PrepareAnonymousWidgetPreviewRequest::EDITOR_NONCE_ATTRIBUTE,
                $result->context->editorNonce,
            );
        }

        try {
            return $next($request);
        } finally {
            // Runs on BOTH the success return above AND any exception
            // thrown by $next()/downstream -- no request-scoped preview
            // state may ever survive past this one request (DoD 1108).
            app()->forgetInstance(WidgetPreviewContext::class);
        }
    }

    private function failureResponse(WidgetPreviewConsumeResult $result): Response
    {
        return match ($result->status) {
            WidgetPreviewConsumeResult::STATUS_MANIFEST_REVOKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_REVOKED,
                'A Manifest referenced by this preview session has been revoked.',
            ),
            WidgetPreviewConsumeResult::STATUS_MANIFEST_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by this preview session is pending approval.',
            ),
            WidgetPreviewConsumeResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCKED,
                'A Manifest referenced by this preview session is currently emergency-blocked.',
            ),
            default => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PREVIEW_SESSION_EXPIRED,
                'This preview session is invalid, expired, or already used.',
            ),
        };
    }
}
