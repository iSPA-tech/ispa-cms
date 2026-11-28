<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Support\Widget\LegacyWidgetRenderer;
use App\Support\Widget\WidgetPreviewEditorScript;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use Cms\Widget\Preview\WidgetPreviewContext;
use Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlReader;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementResolver;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;

/**
 * Module-owned implementation of the root {@see WidgetSlotRuntime} contract
 * (plan Chunk 2 Task 15, design notes; Chunk 2 Task 24, lines 1254-1291,
 * DoD 1280). Rebound over the root
 * {@see \App\Support\Widget\NullWidgetSlotRuntime} default in
 * WidgetServiceProvider::boot().
 *
 * TASK 15's ORIGINAL CEILING (declared Slot + fallback only) is now the
 * FALLBACK tier, not the whole story: {@see self::render()} first attempts
 * REAL, cache-backed, published-Release Placement resolution
 * ({@see self::renderFromPublishedPlacements()}); only when that produces
 * nothing (no Layout/Release yet for this project, no matching Placement,
 * or every matching candidate failed to render) does it fall through to
 * the original declared-fallback-or-empty behavior, UNCHANGED byte-for-byte
 * -- this is what keeps "no-Release output unchanged"
 * (`ExampleProjectWidgetSlotRuntimeTest`, Task 23) true by construction.
 *
 * RENDER CONTEXT: published resolution uses the same three tiers as the
 * preview overlay. The template tier is the Slot's own registered Template
 * Context key; the target tier is {@see WidgetRenderContext::$targetType}/
 * `$targetId`, which <x-widget-slot> copies from the request-scoped
 * {@see \App\Support\Widget\WidgetRenderTarget} a page view sets (e.g.
 * a project's reader view announces `page:<post uuid>`). A request that sets no
 * target resolves global + template placements only. (Until 2026-11-28 this
 * path queried `scope_type = 'global'` only, so "this page only" and "by
 * page type" placements published fine but never rendered publicly.)
 * Locale is read from `app()->getLocale()`.
 *
 * EMERGENCY CONTROL, NOT ELIGIBILITY RE-EVALUATION: rendering a PUBLISHED
 * Release's pinned Instance Version deliberately does NOT run the fresh-
 * fingerprint eligibility re-check {@see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer}
 * performs -- architecture.md is explicit: "Eligibility revocation ... does
 * not change bytes resolved by the current active Release; public
 * rendering uses the Release's immutable eligibility_snapshot and does not
 * re-evaluate current approval state." {@see self::renderInstanceVersion()}
 * therefore renders the pinned Widget/Theme directly through the SAME root
 * {@see LegacyWidgetRenderer} every other rendering path in this module
 * composes (never reimplementing canonical-schema resolution or
 * sanitization, per decision 4), and consults ONLY
 * {@see WidgetRuntimeControlReader} -- the ONE axis architecture.md
 * documents as a legitimate CURRENT-STATE override of already-published
 * output ("This is the only eligibility-related operation allowed to alter
 * current active output without publishing a new Layout Release").
 *
 * CACHE (DoD 1277-1278, 1280): {@see WidgetPlacementCache} stores ONLY
 * candidate metadata (`scope_type`, `scope_key`, `target_type`,
 * `target_id`, `position`, `widget_instance_version_id`) keyed by project,
 * locale, Slot, template key, target, and the active Release VERSION
 * NUMBER -- never rendered HTML. A cache MISS loads real
 * {@see WidgetPlacement} rows for the active Release and writes the
 * metadata back; a cache HIT skips the database query entirely. Actual
 * rendering (this method's OWN final step) is NEVER cached, so provider
 * data always stays live.
 *
 * SIBLING FAIL-SOFT: for `stack` cardinality, a candidate that fails to
 * render (unknown Instance Version, emergency-blocked) is skipped, not
 * fatal -- the remaining candidates still render.
 *
 * DELIBERATELY constructible with nullable, self-defaulting arguments for
 * every NEW dependency -- this task's allowed_paths does not include
 * WidgetServiceProvider.php, so the EXISTING single-argument binding
 * closure (`new WidgetSlotRuntimeService($app->make(WidgetSlotRegistry::class))`)
 * must keep working unmodified; Laravel's container still injects REAL
 * resolved instances for every new optional parameter when constructing
 * this class directly via reflection (e.g. for a future rebind), since
 * each new dependency is itself zero-provider-wiring auto-resolvable.
 */
final class WidgetSlotRuntimeService implements WidgetSlotRuntime
{
    public const ERROR_UNKNOWN_SLOT = 'WIDGET_SLOT_UNKNOWN';

    public const ERROR_NO_CONTENT = 'WIDGET_SLOT_NO_CONTENT';

    /** Fail-soft render errorCode: emergency control reports blocked or unavailable for the pinned Manifest. */
    public const ERROR_RUNTIME_CONTROL_BLOCKED = 'WIDGET_RUNTIME_CONTROL_BLOCKED';

    private readonly WidgetPlacementResolver $resolver;

    private readonly WidgetPlacementCache $cache;

    private readonly WidgetRuntimeTelemetry $telemetry;

    /** Interface-typed (LegacyWidgetRenderer implements it) so tests can
     * substitute a deterministic fake; the default resolution is unchanged. */
    private readonly WidgetRenderer $legacyRenderer;

    private readonly WidgetTemplateContextRegistry $templateContexts;

    public function __construct(
        private readonly WidgetSlotRegistry $slots,
        ?WidgetPlacementResolver $resolver = null,
        ?WidgetPlacementCache $cache = null,
        ?WidgetRuntimeTelemetry $telemetry = null,
        ?WidgetRenderer $legacyRenderer = null,
        ?WidgetTemplateContextRegistry $templateContexts = null,
    ) {
        $this->resolver = $resolver ?? new WidgetPlacementResolver();
        $this->cache = $cache ?? new WidgetPlacementCache();
        $this->telemetry = $telemetry ?? new WidgetRuntimeTelemetry();
        $this->legacyRenderer = $legacyRenderer ?? app(LegacyWidgetRenderer::class);
        $this->templateContexts = $templateContexts ?? app(WidgetTemplateContextRegistry::class);
    }

    public function render(string $slotKey, WidgetRenderContext $context): WidgetRenderResult
    {
        $declaration = $this->slots->declarationFor($slotKey);

        if ($declaration === null) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: self::ERROR_UNKNOWN_SLOT);
        }

        // Signed preview session (admin): the decrypted overlay IS the
        // complete intended placement set for this render — it REPLACES
        // published-Release resolution entirely (a draft that emptied a slot
        // must preview as empty, never fall through to the published rows).
        // The declared-fallback tier below still applies unchanged.
        $previewContext = $this->boundPreviewContext($context->projectName);
        $result = $this->resolveSlot($slotKey, $declaration, $context, $previewContext);

        // Area editor preview: every area — also an empty one — is wrapped in a zero-layout marker so
        // the editor bridge script can outline it in place and offer "add" there. Only a signed
        // preview minted with an editor nonce gets it; every other response is unchanged.
        if ($previewContext?->editorNonce !== null) {
            return new WidgetRenderResult(
                html: '<div ' . WidgetPreviewEditorScript::AREA_KEY_ATTRIBUTE . '="' . htmlspecialchars($slotKey, ENT_QUOTES) . '" '
                    . 'style="display:contents">' . $result->html . '</div>',
                ok: true,
            );
        }

        return $result;
    }

    private function resolveSlot(
        string $slotKey,
        WidgetSlotDeclaration $declaration,
        WidgetRenderContext $context,
        ?WidgetPreviewContext $previewContext,
    ): WidgetRenderResult {
        if ($previewContext !== null) {
            $placementResult = $this->renderFromPreviewOverlay($slotKey, $declaration, $context, $previewContext);
        } else {
            $placementResult = $this->renderFromPublishedPlacements($slotKey, $declaration, $context);
        }

        if ($placementResult !== null) {
            return $placementResult;
        }

        // Task 15's original ceiling: declared fallback, byte-for-byte
        // unchanged.
        if ($declaration->fallback === null || $declaration->fallback === '') {
            return new WidgetRenderResult(html: '', ok: false, errorCode: self::ERROR_NO_CONTENT);
        }

        return new WidgetRenderResult(html: $declaration->fallback, ok: true);
    }

    /**
     * The request-scoped preview session installed by
     * {@see \Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession}, or
     * null outside a signed preview request (or for another project).
     */
    private function boundPreviewContext(string $projectName): ?WidgetPreviewContext
    {
        if (!app()->bound(WidgetPreviewContext::class)) {
            return null;
        }

        /** @var WidgetPreviewContext $previewContext */
        $previewContext = app(WidgetPreviewContext::class);

        return $previewContext->projectName === $projectName ? $previewContext : null;
    }

    /**
     * Preview-overlay resolution: same resolver, same per-Instance render
     * path, but candidates come from the session's decrypted overlay rows
     * (already validated at mint EXACTLY like Draft Save) instead of the
     * published Release. Never touches the placement cache — the overlay is
     * ephemeral by definition. Unlike the public entry point's global-only
     * limitation, the preview session DOES know its Template/Target Context,
     * so template- and target-scoped overlay rows resolve here with full
     * fidelity: the template tier uses the SLOT's own registered Template
     * Context key, the target tier the session's minted target.
     *
     * When the session carries an editor nonce, every rendered placement is
     * wrapped in a zero-layout (`display:contents`) marker div carrying the
     * composite identity `slot_key::scope_identity::widget_instance_uuid` —
     * the exact key shape base-admin's placement-canvas-utils derives — for
     * the injected editor bridge script's hit-boxes.
     */
    private function renderFromPreviewOverlay(
        string $slotKey,
        WidgetSlotDeclaration $declaration,
        WidgetRenderContext $context,
        WidgetPreviewContext $previewContext,
    ): ?WidgetRenderResult {
        $rows = array_values(array_filter(
            $previewContext->overlayPlacements,
            static fn (array $row): bool => (string) ($row['slot_key'] ?? '') === $slotKey
                && (bool) ($row['enabled'] ?? true),
        ));

        if ($rows === []) {
            return null;
        }

        $candidates = array_map(WidgetPlacementResolver::candidateFromRow(...), $rows);
        $winners = $this->resolver->resolve(
            $candidates,
            $declaration->cardinality,
            $this->templateContextKeyForSlot($slotKey),
            $previewContext->targetType,
            $previewContext->targetId,
        );

        if ($winners === []) {
            return null;
        }

        $uuidByVersionId = $previewContext->editorNonce === null
            ? []
            : $this->instanceUuidsForVersionIds(array_map(
                static fn ($winner): int => (int) $winner->identity,
                $winners,
            ));

        $htmlParts = [];
        $renderedCount = 0;
        foreach ($winners as $winner) {
            $rendered = $this->renderInstanceVersion((int) $winner->identity, $context);
            $this->telemetry->recordRender($context->projectName, $slotKey, $rendered->ok, $rendered->errorCode, count($winners));

            if ($rendered->ok && $rendered->html !== '') {
                $htmlParts[] = $previewContext->editorNonce === null
                    ? $rendered->html
                    : $this->wrapEditorMarker($rendered->html, $slotKey, $winner, $uuidByVersionId);
                $renderedCount++;
            }
            // Sibling fail-soft, same as the published path.
        }

        if ($renderedCount === 0) {
            return null;
        }

        return new WidgetRenderResult(html: implode('', $htmlParts), ok: true);
    }

    /** Reverse lookup: which registered Template Context owns this Slot. */
    private function templateContextKeyForSlot(string $slotKey): ?string
    {
        foreach ($this->templateContexts->keys() as $templateKey) {
            $slotKeys = $this->templateContexts->slotKeysFor($templateKey) ?? [];
            if (in_array($slotKey, $slotKeys, true)) {
                return $templateKey;
            }
        }

        return null;
    }

    /**
     * @param list<int> $versionIds
     * @return array<int, string> widget_instance_version_id => instance uuid
     */
    private function instanceUuidsForVersionIds(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        $versions = WidgetInstanceVersion::query()
            ->whereIn('id', array_values(array_unique($versionIds)))
            ->get(['id', 'instance_id']);

        $instanceUuids = WidgetInstance::query()
            ->whereIn('id', $versions->pluck('instance_id')->unique()->all())
            ->pluck('uuid', 'id');

        $map = [];
        foreach ($versions as $version) {
            $uuid = $instanceUuids[(int) $version->instance_id] ?? null;
            if ($uuid !== null) {
                $map[(int) $version->id] = (string) $uuid;
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $uuidByVersionId
     */
    private function wrapEditorMarker(string $html, string $slotKey, mixed $winner, array $uuidByVersionId): string
    {
        $uuid = $uuidByVersionId[(int) $winner->identity] ?? null;
        if ($uuid === null) {
            return $html;
        }

        $scopeIdentity = WidgetPlacementResolver::scopeIdentity(
            $winner->scopeType,
            $winner->scopeKey,
            $winner->targetType,
            $winner->targetId,
        );

        $key = htmlspecialchars("{$slotKey}::{$scopeIdentity}::{$uuid}", ENT_QUOTES);
        $slotAttr = htmlspecialchars($slotKey, ENT_QUOTES);

        return '<div ' . WidgetPreviewEditorScript::PLACEMENT_KEY_ATTRIBUTE . '="' . $key . '" '
            . WidgetPreviewEditorScript::SLOT_KEY_ATTRIBUTE . '="' . $slotAttr . '" '
            . 'style="display:contents">' . $html . '</div>';
    }

    /**
     * Real, cache-backed, published-Release Placement resolution. Returns
     * null (never a failure result) whenever there is nothing to render
     * from real Placements, so {@see self::render()} can fall through to
     * the unchanged declared-fallback tier.
     */
    private function renderFromPublishedPlacements(string $slotKey, WidgetSlotDeclaration $declaration, WidgetRenderContext $context): ?WidgetRenderResult
    {
        $layout = WidgetLayout::query()->where('project_name', $context->projectName)->first();
        if ($layout === null || $layout->current_published_version_id === null) {
            return null;
        }

        $release = WidgetLayoutVersion::query()->find($layout->current_published_version_id);
        if ($release === null) {
            return null;
        }

        // Same Render Context the preview overlay uses: the template tier is
        // the Slot's own registered Template Context, the target tier is the
        // page the request announced (WidgetRenderTarget -> WidgetRenderContext).
        $templateKey = $this->templateContextKeyForSlot($slotKey);
        $hasTarget = $context->targetType !== null && $context->targetId !== null;
        $targetType = $hasTarget ? $context->targetType : null;
        $targetId = $hasTarget ? $context->targetId : null;

        $locale = app()->getLocale();
        $cacheKey = WidgetPlacementCache::buildKey($context->projectName, $locale, $slotKey, $templateKey, $targetType, $targetId, $release->version);

        $rows = $this->cache->get($cacheKey);
        if ($rows !== null) {
            $this->telemetry->recordCache($context->projectName, $slotKey, 'hit');
        } else {
            $rows = WidgetPlacement::query()
                ->where('layout_version_id', $release->id)
                ->where('slot_key', $slotKey)
                ->where(static function ($query) use ($templateKey, $targetType, $targetId): void {
                    $query->where('scope_type', WidgetPlacementResolver::SCOPE_GLOBAL);
                    if ($templateKey !== null) {
                        $query->orWhere(static fn ($q) => $q
                            ->where('scope_type', WidgetPlacementResolver::SCOPE_TEMPLATE)
                            ->where('scope_key', $templateKey));
                    }
                    if ($targetId !== null) {
                        $query->orWhere(static fn ($q) => $q
                            ->where('scope_type', WidgetPlacementResolver::SCOPE_TARGET)
                            ->where('target_type', $targetType)
                            ->where('target_id', $targetId));
                    }
                })
                ->where('enabled', true)
                ->get(['scope_type', 'scope_key', 'target_type', 'target_id', 'position', 'widget_instance_version_id'])
                ->map(static fn (WidgetPlacement $placement): array => [
                    'scope_type' => $placement->scope_type,
                    'scope_key' => $placement->scope_key,
                    'target_type' => $placement->target_type,
                    'target_id' => $placement->target_id,
                    'position' => $placement->position,
                    'widget_instance_version_id' => (int) $placement->widget_instance_version_id,
                ])
                ->values()
                ->all();

            $this->cache->put($cacheKey, $rows, $context->projectName, $layout->uuid);
            $this->telemetry->recordCache($context->projectName, $slotKey, 'miss');
        }

        if ($rows === []) {
            return null;
        }

        $candidates = array_map(WidgetPlacementResolver::candidateFromRow(...), $rows);
        $winners = $this->resolver->resolve($candidates, $declaration->cardinality, $templateKey, $targetType, $targetId);

        if ($winners === []) {
            return null;
        }

        $htmlParts = [];
        $renderedCount = 0;
        foreach ($winners as $winner) {
            $rendered = $this->renderInstanceVersion((int) $winner->identity, $context);
            $this->telemetry->recordRender($context->projectName, $slotKey, $rendered->ok, $rendered->errorCode, count($winners));

            if ($rendered->ok && $rendered->html !== '') {
                $htmlParts[] = $rendered->html;
                $renderedCount++;
            }
            // Sibling fail-soft: a failed candidate is skipped, never fatal
            // to the others.
        }

        if ($renderedCount === 0) {
            return null;
        }

        return new WidgetRenderResult(html: implode('', $htmlParts), ok: true);
    }

    /**
     * Renders ONE pinned Instance Version -- emergency control only, never
     * eligibility re-evaluation. See class docblock "EMERGENCY CONTROL, NOT
     * ELIGIBILITY RE-EVALUATION".
     */
    private function renderInstanceVersion(int $instanceVersionId, WidgetRenderContext $context): WidgetRenderResult
    {
        $instanceVersion = WidgetInstanceVersion::query()->find($instanceVersionId);
        if ($instanceVersion === null) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: self::ERROR_NO_CONTENT);
        }

        if ($instanceVersion->artifact_manifest_id !== null) {
            $manifest = WidgetArtifactManifest::query()->find($instanceVersion->artifact_manifest_id);

            if ($manifest !== null) {
                // Fresh per call, never stored as a property -- neither
                // this class nor WidgetRuntimeControlReader is safe to
                // memoize across requests (see that class's own
                // "MEMOIZATION" docblock section); resolving it here every
                // call guarantees it always observes the CURRENT request's
                // attribute bag.
                $controlReader = app(WidgetRuntimeControlReader::class);
                $state = $controlReader->readState($context->projectName, $manifest->manifest_hash);

                if ($state === WidgetRuntimeControlReader::STATE_BLOCKED || $state === WidgetRuntimeControlReader::STATE_UNAVAILABLE) {
                    return new WidgetRenderResult(html: '', ok: false, errorCode: self::ERROR_RUNTIME_CONTROL_BLOCKED);
                }
            }
        }

        return $this->legacyRenderer->render(
            new WidgetRenderSpec(
                $instanceVersion->widget_id,
                $instanceVersion->widget_theme_id,
                (array) ($instanceVersion->values ?? []),
            ),
            $context,
        );
    }
}
