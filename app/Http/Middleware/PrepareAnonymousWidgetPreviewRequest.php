<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Root, module-agnostic first stage of the Widget signed preview session
 * transport (plan Chunk 2 Task 19, DoD 1107; architecture.md "Public
 * preview request"; docs/architecture/adr-widget-preview-session.md
 * decision 5), AND the root Task-5a-named integration point for the
 * PERMANENT Site Layout full-page/CDN cache bypass (plan Chunk 2 Task 24,
 * DoD 1283-1284; architecture.md "Site Layout MVP permanently bypasses
 * full-page/CDN caching for every response containing Site Layout
 * output... Task 24 wires the Task-5a-named bypass at or before cache
 * lookup").
 *
 * Registered in the GLOBAL middleware stack (`app/Http/Kernel.php`
 * `$middleware`), BEFORE `Localization`, and therefore before the entire
 * `web`-group pipeline (`EncryptCookies`, `StartSession`,
 * `ShareErrorsFromSession`, `VerifyCsrfToken`, auth resolution,
 * personalization/experiments, and any full-page cache) -- see the ADR's
 * verified middleware topology citing `Kernel.php:56-67,75-84`. Being the
 * FIRST middleware in the entire global stack is precisely what makes
 * {@see self::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE} satisfy "before cache
 * lookup" (DoD 1284) by construction: there is structurally nothing
 * earlier in the request lifecycle that could perform one.
 *
 * IMPORTS NO WIDGET-MODULE CLASS (D-040, FR-31) -- this file must never
 * reference a module class or the module boundary this codebase enforces
 * everywhere else is broken for the one middleware most responsible for
 * anonymous credential safety. It recognizes ONLY:
 * - the reserved query marker {@see self::TOKEN_PARAMETER};
 * - a "structurally valid server signature candidate", proven via
 *   Laravel's OWN signed-URL macro `$request->hasValidSignature()`
 *   (`Illuminate\Routing\Middleware\ValidateSignature` already uses the
 *   sibling `hasValidSignatureWhileIgnoring()` in this codebase, proving
 *   the macro is wired -- see `Kernel.php:111`'s `signed` alias).
 *
 * It does NOT decrypt, look up, or consume the token itself -- that is
 * the Widget module's `ResolveWidgetPreviewSession` middleware's job,
 * running later as route-aware `web`-group middleware. This class only:
 * 1. UNCONDITIONALLY, for EVERY request regardless of preview candidacy,
 *    marks {@see self::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE} before doing
 *    anything else (Task 24, see "SITE LAYOUT CACHE BYPASS" below);
 * 2. for a structurally valid preview candidate specifically, strips
 *    `Cookie`, `Authorization`, session/XSRF request input BEFORE any
 *    downstream middleware (including `EncryptCookies`/`StartSession`)
 *    can observe them;
 * 3. marks the request as an accepted preview candidate (a request
 *    attribute the module middleware reads);
 * 4. on the way back out, strips any `Set-Cookie` response header
 *    (suppresses preview session cookies) and applies the frozen response
 *    controls (`Cache-Control: no-store, private`, `Referrer-Policy:
 *    no-referrer`, restrictive CSP with explicit `frame-ancestors`,
 *    reconciling any conflicting `X-Frame-Options`) -- these apply
 *    uniformly to every accepted candidate response, success or failure,
 *    since a failed consume must be exactly as protected as a successful
 *    one (defense in depth; DoD 1110/1111);
 * 5. for every OTHER (non-preview-candidate) request, still applies the
 *    permanent `Cache-Control: no-store, private` bypass header on the way
 *    out (Task 24).
 *
 * An invalid preview candidate (marker absent, OR signature
 * invalid/tampered) receives zero PREVIEW-specific behavior -- ordinary
 * traffic pays no preview cost (DoD 1107: "Invalid candidates do not
 * receive preview behavior"). It STILL receives the permanent Site Layout
 * cache-bypass marking/header, which is unconditional by design (see
 * "SITE LAYOUT CACHE BYPASS" below).
 *
 * SITE LAYOUT CACHE BYPASS (Task 24): this file cannot import a
 * Widget-module class (D-040 above), so it cannot inspect Widget-module
 * state to learn which specific routes render Site Layout output. Task
 * 5a's own preflight evidence
 * (docs/evidence/widget-site-layout-preflight.md Sec 5) proved no
 * app-level or nginx full-page cache exists anywhere in this codebase
 * today (`spatie/responsecache` absent/unwired; no `fastcgi_cache`/
 * `proxy_cache` directive in any enabled nginx site), so applying the
 * bypass UNCONDITIONALLY to every request carries ZERO regression risk
 * against real caching behavior -- it is the same conservative,
 * evidence-gated default this codebase's own gap register (G1-G9) applies
 * throughout. {@see self::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE} is the
 * app-side signal a root/app cache-lookup component (present or future)
 * MUST treat as "never consult the cache for this request, and never
 * satisfy it from an already-warmed entry" -- proven in
 * `WidgetRuntimeCacheTelemetryIntegrationTest` via a purpose-built
 * simulated cache-lookup stand-in (this file cannot depend on a real one,
 * since none exists). The paired `Cache-Control: no-store, private`
 * response header is the ONLY signal that can ever reach a real CDN
 * (Cloudflare) at all, since an edge has no visibility into this
 * in-process request attribute; whether Cloudflare's zone actually honors
 * it is the explicit [OPS-RESIDUAL] this class cannot prove (same gap G4
 * the Widget module's own runtime-control topology guard class docblock
 * already records) -- a late header alone does not pass DoD 1284, which is
 * why the REQUEST-side mark above is unconditional and first, not an
 * afterthought paired only with the response header.
 */
final class PrepareAnonymousWidgetPreviewRequest
{
    /**
     * Reserved query marker carrying the opaque single-use token. Never a
     * header (tokens must never rely on a caller adding a custom header to
     * a plain anchor-tag/iframe `src` visit) and never anything but a
     * query parameter Laravel's own signed-URL signature already covers.
     */
    public const TOKEN_PARAMETER = '_widget_preview';

    /**
     * Request attribute set on an ACCEPTED structurally-valid candidate.
     * Read by the later route-aware module middleware; never read outside
     * this preview transport.
     */
    public const CANDIDATE_ATTRIBUTE = '_widget_preview_candidate';

    /**
     * Request attribute marking the permanent Site Layout full-page/CDN
     * cache-bypass decision (Chunk 2 Task 24, DoD 1283-1284). See class
     * docblock "SITE LAYOUT CACHE BYPASS".
     */
    public const SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE = '_widget_site_layout_cache_bypass';

    /**
     * Request attribute the module's ResolveWidgetPreviewSession sets AFTER
     * a successful consume when the minted session carries an editor nonce
     * (the admin Placements editor's clickable-preview mode). Presence
     * switches {@see self::applyResponseControls()} from the frozen
     * `script-src 'none'` posture to a per-response CSP nonce that allows
     * EXACTLY ONE injected inline script — the editor bridge
     * ({@see \App\Support\Widget\WidgetPreviewEditorScript}) — and nothing
     * else. Ordinary (non-editor) preview responses are byte-identical to
     * before. Set as a request attribute (a plain string) precisely so this
     * root class keeps importing no Widget-module class (D-040).
     */
    public const EDITOR_NONCE_ATTRIBUTE = '_widget_preview_editor_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // Permanent Site Layout cache bypass (Task 24): the FIRST thing
        // this method does, for EVERY request, so it runs before any other
        // global/web-group middleware and before any cache lookup could
        // ever occur -- see class docblock.
        $request->attributes->set(self::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE, true);

        if (!$this->isStructurallyValidCandidate($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $this->applySiteLayoutCacheBypassHeader($response);
        }

        $this->stripCredentialInputs($request);
        $request->attributes->set(self::CANDIDATE_ATTRIBUTE, true);

        /** @var Response $response */
        $response = $next($request);

        return $this->applyResponseControls($response, $request);
    }

    private function isStructurallyValidCandidate(Request $request): bool
    {
        $token = $request->query(self::TOKEN_PARAMETER);

        if (!is_string($token) || $token === '') {
            return false;
        }

        // Laravel's own HMAC signature (APP_KEY-derived) already covers
        // every query parameter on this URL, including our token -- so
        // tampering with the token value invalidates the signature too,
        // which is exactly why no separate token-shape check is needed
        // here (that is ResolveWidgetPreviewSession's job, against the
        // real store).
        return $request->hasValidSignature();
    }

    /**
     * Applies the permanent Site Layout cache-bypass response header (Task
     * 24) to every request that is NOT an accepted preview candidate
     * (accepted candidates already get an equal-or-stronger `Cache-Control:
     * no-store, private` from {@see self::applyResponseControls()}).
     *
     * NOT a naive `!$response->headers->has('Cache-Control')` check: Symfony's
     * `Response` ALWAYS computes some `Cache-Control` value, even with zero
     * explicit configuration (`ResponseHeaderBag`'s own default, verified
     * empirically as `no-cache, private` for both `Illuminate\Http\Response`
     * and `Illuminate\Http\JsonResponse`) -- so `has()` is true for nearly
     * every response regardless of whether a route made a real decision,
     * which would make this method almost never fire. Instead, this method
     * overrides UNLESS the current value carries a genuine positive-caching
     * directive (`max-age`, `s-maxage`, or `public`) -- the only reliable
     * signal, available without importing a Widget-module class, that a
     * route deliberately opted into caching rather than merely inheriting
     * the framework's own unconfigured default. `no-cache` alone is NOT
     * such a signal: HTTP `no-cache` still permits a cache to STORE the
     * response and revalidate it later, which is weaker than the `no-store`
     * this bypass requires and is exactly the framework default, not an
     * opt-in.
     *
     * Confirmed empirically against two real regressions before landing on
     * this predicate: an unconditional override broke
     * `Cms\Core\Tests\Feature\AppI18nManifestTest`'s deliberate
     * `Cache-Control: max-age=300, public` on an unrelated mobile-app i18n
     * manifest endpoint; a naive `has()` check then made the bypass inert
     * for ordinary routes because of the Symfony default described above.
     */
    private function applySiteLayoutCacheBypassHeader(Response $response): Response
    {
        $current = (string) $response->headers->get('Cache-Control', '');

        $isDeliberatelyCacheable = str_contains($current, 'max-age')
            || str_contains($current, 's-maxage')
            || str_contains($current, 'public');

        if (!$isDeliberatelyCacheable) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function stripCredentialInputs(Request $request): void
    {
        $request->headers->remove('Cookie');
        $request->headers->remove('Authorization');
        $request->headers->remove('X-XSRF-TOKEN');
        $request->headers->remove('X-CSRF-TOKEN');
        $request->cookies->replace([]);
        $request->attributes->set('_widget_preview_stripped', true);
    }

    private function applyResponseControls(Response $response, Request $request): Response
    {
        // Suppress any preview session cookie: StartSession (which never
        // saw a real Cookie header, per stripCredentialInputs()) would
        // otherwise still queue a fresh anonymous session cookie on the
        // way out. A preview response must set none.
        $response->headers->remove('Set-Cookie');

        // App-emitted cache-bypass marker (DoD 1111): no-store also
        // instructs any CDN/reverse-proxy in front of this origin to never
        // cache this response.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Editor mode (admin clickable preview): the module middleware set
        // EDITOR_NONCE_ATTRIBUTE after a successful consume. Inject exactly
        // one CSP-nonced inline script (the editor bridge) and allow ONLY
        // that script; every other directive stays as frozen below.
        $editorNonce = $request->attributes->get(self::EDITOR_NONCE_ATTRIBUTE);
        $scriptSrc = "script-src 'none'";

        if (is_string($editorNonce) && $editorNonce !== '' && $this->isHtmlResponse($response)) {
            $cspNonce = bin2hex(random_bytes(16));
            $scriptSrc = "script-src 'nonce-{$cspNonce}'";
            $this->injectEditorScript($response, $editorNonce, $cspNonce);
        }

        // `frame-ancestors 'self'` covers production (admin served from the
        // same origin under /iPanel). A split-origin admin (local dev) needs
        // its origin allowlisted explicitly via config — never a wildcard.
        $frameAncestors = trim((string) config('ispa-cms.widget_preview_frame_ancestors', ''));

        $response->headers->set('Content-Security-Policy', implode('; ', [
            $scriptSrc,
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            rtrim("frame-ancestors 'self' " . $frameAncestors),
        ]));

        // Reconcile existing X-Frame-Options: a global DENY/SAMEORIGIN set
        // upstream would block the very admin-iframe embedding the CSP
        // frame-ancestors directive above is meant to allow. Modern
        // browsers prefer CSP frame-ancestors when both are present, but
        // this response removes any conflicting legacy header rather than
        // relying on that precedence silently.
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === '' || str_contains($contentType, 'text/html');
    }

    /** Injects the editor bridge before </body> (appends when no body tag). */
    private function injectEditorScript(Response $response, string $editorNonce, string $cspNonce): void
    {
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        $tag = \App\Support\Widget\WidgetPreviewEditorScript::tag($editorNonce, $cspNonce);

        $position = strripos($content, '</body>');
        $content = $position === false
            ? $content . $tag
            : substr($content, 0, $position) . $tag . substr($content, $position);

        $response->setContent($content);
    }
}
