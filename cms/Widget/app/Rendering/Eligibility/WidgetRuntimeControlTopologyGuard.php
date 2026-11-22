<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Predis\ClientInterface;

/**
 * Pre-mutation topology precondition for the emergency Manifest runtime
 * block/clear endpoint (plan Chunk 2 Task 15c checklist items 3-4;
 * docs/architecture/adr-widget-artifact-store.md decision 11; D-045).
 *
 * Proves two things must be true before ANY emergency block/clear write is
 * allowed: (1) the control source is shared and read-after-write consistent
 * across every public node, and (2) every response containing Site Layout
 * output PERMANENTLY bypasses full-page/CDN caching. On the current proven
 * production topology (single VM, single local disk, Cloudflare fronting
 * every domain), (1) is proven (evidence §5/§6: shared Redis, one host) but
 * (2) is NOT -- Cloudflare's cache/page rules cannot be observed or created
 * from this repository (preflight gaps G4, G2/G6). The artifact-store ADR
 * therefore accepts the CONTRACT while recording that on current evidence
 * the endpoint stays unavailable and performs no mutation.
 *
 * DELIBERATELY NOT AN INTERFACE, DELIBERATELY NOT FINAL: this task's
 * allowed_paths includes exactly one file for this class and does not
 * include WidgetServiceProvider.php, so there is no container binding this
 * task may add. A plain concrete, non-final class is auto-resolved by
 * Laravel's container via reflection with ZERO provider wiring -- every
 * environment (production, and every test that does not explicitly
 * override the container binding) gets this class's own {@see self::isAvailable()}
 * body, which hardcodes `false`. A feature test proves the CAS/200 path
 * exists by binding an anonymous subclass that overrides isAvailable() to
 * return true (`$this->app->bind(self::class, fn () => new class extends
 * WidgetRuntimeControlTopologyGuard { ... })`) -- legal only because this
 * class is not `final`, a deliberate, disclosed, test-only exception to
 * this module's usual `final class` discipline (see
 * {@see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer}, which IS final
 * and has no such requirement).
 *
 * PRODUCTION MUST NEVER FLIP THIS TO true. Task 24 is the named owner of
 * wiring the real Task-5a-named cache-lookup/CDN bypass; Task 24b is the
 * named owner of proving cross-node/edge behavior. Until both land and a
 * new evidence-backed implementation replaces this class's hardcoded
 * `false`, {@see self::isAvailable()} must keep returning false in every
 * real request. Do not infer readiness from a response header added after
 * a full-page cache lookup, or from any other indirect signal -- only a
 * real Task 24/24b implementation may change this return value.
 */
class WidgetRuntimeControlTopologyGuard
{
    /**
     * Whether the emergency block/clear endpoint may proceed to mutate.
     * Hardcoded false in production; see class docblock for the test-only
     * override mechanism and the Task 24/24b gate that must land before
     * this may ever legitimately return true outside a test.
     */
    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * Cross-node shared-store read-after-write PROBE (plan Chunk 2 Task 24,
     * DoD 1284's SECOND precondition: "cross-node shared-store probes").
     * Proves -- against a REAL shared store reached through two
     * INDEPENDENT client instances (simulating two separate public nodes,
     * exactly as {@see \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlReader}'s
     * own "MEMOIZATION" docblock section already treats each fresh
     * php-fpm request as its own node observation) -- that a value written
     * through one is immediately visible through the other.
     *
     * This is NECESSARY but NOT SUFFICIENT evidence for
     * {@see self::isAvailable()}: DoD 1284's FIRST precondition (a real
     * Cloudflare zone cache/page-rule export proving the edge honors the
     * app-emitted bypass) cannot be produced from this repository (same
     * gap G4 this class's own docblock already records), so a PASSING
     * probe here must NEVER, by itself, flip {@see self::isAvailable()} --
     * see that method's own docblock, unchanged by this addition.
     * [OPS-RESIDUAL]: only Task 24b (or a real ops runbook combining this
     * probe's result with the still-missing Cloudflare evidence) may
     * legitimately change {@see self::isAvailable()}'s hardcoded `false`.
     *
     * Deliberately accepts two client instances rather than constructing
     * its own -- this method has no opinion about which store/connection
     * config is under test, mirroring
     * {@see \Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache}'s own
     * "the caller supplies the client" posture for the identical reason
     * (WidgetServiceProvider.php is outside this task's allowed_paths, so
     * there is no dedicated container-managed connection this method could
     * assume).
     */
    public function isCrossNodeSharedStoreConsistent(ClientInterface $writerNode, ClientInterface $readerNode): bool
    {
        $probeKey = self::PROBE_KEY_PREFIX . bin2hex(random_bytes(8));
        $probeValue = bin2hex(random_bytes(8));

        $writerNode->set($probeKey, $probeValue, 'EX', 30);
        $observed = $readerNode->get($probeKey);
        $writerNode->del([$probeKey]);

        return $observed !== null && $observed !== false && (string) $observed === $probeValue;
    }

    private const PROBE_KEY_PREFIX = 'widget_runtime_control_topology_probe:';
}
