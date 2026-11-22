<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Observability;

use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Chunk 2 Task 24: safe runtime telemetry (plan lines 1254-1291, DoD 1279).
 * Logs render/provider/cache/preview/publish/rollback metrics WITHOUT
 * values, full HTML, or tokens -- every public method signature only
 * accepts safe scalar identity fields (never a whole model/DTO/array of
 * caller-supplied Input Values), which is what makes the "no sensitive
 * payload" guarantee structural rather than a convention a future edit
 * could quietly violate.
 *
 * Uses a recording {@see LoggerInterface} test double (no real log file
 * I/O) injected via the constructor -- the same zero-provider-wiring,
 * nullable-default-argument pattern this task's other new classes use
 * (WidgetServiceProvider.php is outside this task's allowed_paths).
 */
final class WidgetRuntimeTelemetryTest extends TestCase
{
    private function recordingLogger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    // -----------------------------------------------------------------
    // Zero-provider-wiring default construction.
    // -----------------------------------------------------------------

    public function test_is_constructible_with_zero_arguments(): void
    {
        $telemetry = new WidgetRuntimeTelemetry();

        // Must not throw -- uses the application's real default logger.
        $telemetry->recordCache('ExampleProject', 'site.header.before', 'hit');

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // recordRender()
    // -----------------------------------------------------------------

    public function test_record_render_logs_the_safe_identity_fields(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordRender('ExampleProject', 'site.header.before', true, null, 2);

        $this->assertCount(1, $logger->entries);
        $entry = $logger->entries[0];

        $this->assertSame('widget.runtime_telemetry.render', $entry['message']);
        $this->assertSame([
            'event' => 'render',
            'project' => 'ExampleProject',
            'slot_key' => 'site.header.before',
            'ok' => true,
            'error_code' => null,
            'candidate_count' => 2,
        ], $entry['context']);
    }

    public function test_record_render_never_leaks_html_or_values(): void
    {
        // Structural proof: recordRender()'s own signature has no
        // parameter through which HTML or Input Values could travel --
        // the context array's key set is a fixed allowlist regardless of
        // what a caller passes.
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordRender('ExampleProject', 'site.header.before', false, 'WIDGET_SLOT_NO_CONTENT', 0);

        $context = $logger->entries[0]['context'];
        $this->assertSame(['event', 'project', 'slot_key', 'ok', 'error_code', 'candidate_count'], array_keys($context));
    }

    // -----------------------------------------------------------------
    // recordProvider()
    // -----------------------------------------------------------------

    public function test_record_provider_logs_the_safe_identity_fields(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordProvider('ExampleProject', 'post.latest', 1, true, null);

        $entry = $logger->entries[0];
        $this->assertSame('widget.runtime_telemetry.provider', $entry['message']);
        $this->assertSame([
            'event' => 'provider',
            'project' => 'ExampleProject',
            'provider_key' => 'post.latest',
            'provider_version' => 1,
            'ok' => true,
            'error_code' => null,
        ], $entry['context']);
    }

    // -----------------------------------------------------------------
    // recordCache()
    // -----------------------------------------------------------------

    public function test_record_cache_logs_the_outcome(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordCache('ExampleProject', 'site.header.before', 'hit');

        $entry = $logger->entries[0];
        $this->assertSame('widget.runtime_telemetry.cache', $entry['message']);
        $this->assertSame([
            'event' => 'cache',
            'project' => 'ExampleProject',
            'slot_key' => 'site.header.before',
            'outcome' => 'hit',
        ], $entry['context']);
    }

    public function test_record_cache_rejects_an_unknown_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new WidgetRuntimeTelemetry($this->recordingLogger()))->recordCache('ExampleProject', 'site.header.before', 'bogus');
    }

    // -----------------------------------------------------------------
    // recordPreview()
    // -----------------------------------------------------------------

    public function test_record_preview_logs_the_safe_identity_fields(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordPreview('ExampleProject', 'layout-uuid-1', 'mint', true);

        $entry = $logger->entries[0];
        $this->assertSame('widget.runtime_telemetry.preview', $entry['message']);
        $this->assertSame([
            'event' => 'preview',
            'project' => 'ExampleProject',
            'layout_uuid' => 'layout-uuid-1',
            'stage' => 'mint',
            'ok' => true,
        ], $entry['context']);
    }

    public function test_record_preview_never_receives_a_token_field(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordPreview('ExampleProject', 'layout-uuid-1', 'consume', false);

        $this->assertArrayNotHasKey('token', $logger->entries[0]['context']);
    }

    // -----------------------------------------------------------------
    // recordPublish() / recordRollback()
    // -----------------------------------------------------------------

    public function test_record_publish_logs_project_layout_release_and_correlation(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordPublish('ExampleProject', 'layout-uuid-1', 5, 'corr-123');

        $entry = $logger->entries[0];
        $this->assertSame('widget.runtime_telemetry.publish', $entry['message']);
        $this->assertSame([
            'event' => 'publish',
            'project' => 'ExampleProject',
            'layout_uuid' => 'layout-uuid-1',
            'release_version' => 5,
            'correlation_id' => 'corr-123',
        ], $entry['context']);
    }

    public function test_record_rollback_logs_source_and_destination_release_versions(): void
    {
        $logger = $this->recordingLogger();
        (new WidgetRuntimeTelemetry($logger))->recordRollback('ExampleProject', 'layout-uuid-1', 6, 3, 'corr-456');

        $entry = $logger->entries[0];
        $this->assertSame('widget.runtime_telemetry.rollback', $entry['message']);
        $this->assertSame([
            'event' => 'rollback',
            'project' => 'ExampleProject',
            'layout_uuid' => 'layout-uuid-1',
            'release_version' => 6,
            'source_release_version' => 3,
            'correlation_id' => 'corr-456',
        ], $entry['context']);
    }

    // -----------------------------------------------------------------
    // Listener-failure observability (DoD 1282): recording itself must
    // never throw even if the underlying logger explodes.
    // -----------------------------------------------------------------

    public function test_a_throwing_logger_does_not_propagate_out_of_record_calls(): void
    {
        $telemetry = new WidgetRuntimeTelemetry(new ThrowingLogger());

        // Must not throw.
        $telemetry->recordCache('ExampleProject', 'site.header.before', 'miss');

        $this->assertTrue(true);
    }
}

/**
 * Minimal in-memory PSR-3 logger test double: records every call instead of
 * writing to a real log file, so assertions can inspect exact
 * message/context without depending on log-file parsing.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * Simulates a genuinely broken logging backend (e.g. a full disk or
 * unreachable log aggregator) to prove telemetry recording itself is
 * fail-soft and observable, never able to surface as an exception to a
 * caller (DoD 1282's "safe and observable" posture, reused here for
 * telemetry specifically, not just the listeners that call it).
 */
final class ThrowingLogger implements LoggerInterface
{
    public function emergency($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function alert($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function critical($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function error($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function warning($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function notice($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function info($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function debug($message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }

    public function log($level, $message, array $context = []): void
    {
        throw new \RuntimeException('Simulated logging backend outage.');
    }
}
