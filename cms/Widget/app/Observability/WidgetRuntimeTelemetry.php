<?php

declare(strict_types=1);

namespace Cms\Widget\Observability;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Safe runtime telemetry recorder (plan Chunk 2 Task 24, lines 1254-1291,
 * DoD 1279). Logs render/provider/cache/preview/publish/rollback metrics
 * WITHOUT values, full HTML, or tokens.
 *
 * The "no sensitive payload" guarantee is STRUCTURAL, not a convention a
 * future edit could quietly violate: every public method's signature only
 * accepts safe scalar identity fields (project name, Slot/provider key,
 * layout uuid, release version, ok/errorCode, a fixed cache-outcome enum,
 * a correlation id) -- there is no parameter anywhere through which a
 * caller-supplied Input Value, rendered HTML string, or preview/session
 * token could reach the logged context, mirroring
 * {@see \Cms\Widget\Audit\WidgetAuditWriter}'s own banned-field discipline
 * and {@see \Cms\Widget\Events\WidgetLayoutPublished}'s identical
 * "identity only" posture.
 *
 * DELIBERATELY constructible with a nullable, self-defaulting logger
 * argument -- this task's allowed_paths does not include
 * WidgetServiceProvider.php, so there is no container binding this task may
 * add (same zero-provider-wiring reasoning as
 * {@see \Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache} and
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard}).
 * Laravel's container auto-resolves this class via reflection with the
 * default (null) argument, which falls back to the application's own
 * default log channel via the `Log` facade -- zero additional wiring
 * needed for real production use.
 *
 * Recording itself is fail-soft: a broken logging backend (disk full,
 * unreachable aggregator) is caught and reported, never allowed to
 * propagate out of a `record*()` call and interrupt the render/provider/
 * cache/preview/publish/rollback path it is merely observing -- the same
 * "non-durable after-commit work cannot turn a committed response into an
 * error" posture architecture.md requires of the Task 24 listeners,
 * applied here one layer down since the listeners themselves delegate
 * logging to this class.
 */
final class WidgetRuntimeTelemetry
{
    private const EVENT_RENDER = 'render';

    private const EVENT_PROVIDER = 'provider';

    private const EVENT_CACHE = 'cache';

    private const EVENT_PREVIEW = 'preview';

    private const EVENT_PUBLISH = 'publish';

    private const EVENT_ROLLBACK = 'rollback';

    /** Closed enum of cache telemetry outcomes -- anything else is a caller bug, not a runtime condition, so it throws rather than silently logging garbage. */
    private const VALID_CACHE_OUTCOMES = ['hit', 'miss', 'write', 'invalidate'];

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? Log::getFacadeRoot();
    }

    public function recordRender(string $projectName, string $slotKey, bool $ok, ?string $errorCode, int $candidateCount): void
    {
        $this->safeLog(self::EVENT_RENDER, [
            'event' => self::EVENT_RENDER,
            'project' => $projectName,
            'slot_key' => $slotKey,
            'ok' => $ok,
            'error_code' => $errorCode,
            'candidate_count' => $candidateCount,
        ]);
    }

    public function recordProvider(string $projectName, string $providerKey, int $providerVersion, bool $ok, ?string $errorCode): void
    {
        $this->safeLog(self::EVENT_PROVIDER, [
            'event' => self::EVENT_PROVIDER,
            'project' => $projectName,
            'provider_key' => $providerKey,
            'provider_version' => $providerVersion,
            'ok' => $ok,
            'error_code' => $errorCode,
        ]);
    }

    /**
     * @throws InvalidArgumentException on an unrecognized $outcome -- a
     *         programming error at the call site, not a runtime condition,
     *         so this validates BEFORE the fail-soft logging wrapper below
     *         rather than being swallowed by it.
     */
    public function recordCache(string $projectName, string $slotKey, string $outcome): void
    {
        if (!in_array($outcome, self::VALID_CACHE_OUTCOMES, true)) {
            throw new InvalidArgumentException("Unknown cache telemetry outcome '{$outcome}'.");
        }

        $this->safeLog(self::EVENT_CACHE, [
            'event' => self::EVENT_CACHE,
            'project' => $projectName,
            'slot_key' => $slotKey,
            'outcome' => $outcome,
        ]);
    }

    public function recordPreview(string $projectName, string $layoutUuid, string $stage, bool $ok): void
    {
        $this->safeLog(self::EVENT_PREVIEW, [
            'event' => self::EVENT_PREVIEW,
            'project' => $projectName,
            'layout_uuid' => $layoutUuid,
            'stage' => $stage,
            'ok' => $ok,
        ]);
    }

    public function recordPublish(string $projectName, string $layoutUuid, int $releaseVersion, string $correlationId): void
    {
        $this->safeLog(self::EVENT_PUBLISH, [
            'event' => self::EVENT_PUBLISH,
            'project' => $projectName,
            'layout_uuid' => $layoutUuid,
            'release_version' => $releaseVersion,
            'correlation_id' => $correlationId,
        ]);
    }

    public function recordRollback(string $projectName, string $layoutUuid, int $releaseVersion, int $sourceReleaseVersion, string $correlationId): void
    {
        $this->safeLog(self::EVENT_ROLLBACK, [
            'event' => self::EVENT_ROLLBACK,
            'project' => $projectName,
            'layout_uuid' => $layoutUuid,
            'release_version' => $releaseVersion,
            'source_release_version' => $sourceReleaseVersion,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $category, array $context): void
    {
        try {
            $this->logger->info('widget.runtime_telemetry.' . $category, $context);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
