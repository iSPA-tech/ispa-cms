<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Builds a configuration package for an export run in the background.
 *
 * Reports progress per section as admin i18n keys with done/total counts (no section names), validates the package and checks it for secrets, then stores
 * the payload, checksum and a values-free summary on the run. On any failure the run is marked failed, no payload
 * is stored and only a safe message is kept: exception messages are never copied because they may contain values.
 */
class ExportConfigPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int                  $runId   Id of the ConfigPackageRun to fill.
     * @param array<string, mixed> $options Export options (see ConfigPackageExporter::export()).
     */
    public function __construct(private readonly int $runId, private readonly array $options = [])
    {
    }

    /**
     * Run the export.
     */
    public function handle(ConfigPackageExporter $exporter, BackgroundTaskRecorder $recorder): void
    {
        $run = ConfigPackageRun::query()->find($this->runId);

        if ($run === null || $run->status !== ConfigPackageRun::STATUS_QUEUED) {
            return;
        }

        $sections = array_values((array) $run->sections);
        $task = $recorder->start('config.package.export', 'Core', $run->created_by);
        $run->forceFill(['status' => ConfigPackageRun::STATUS_RUNNING, 'background_task_uuid' => $task->uuid])->save();
        $recorder->progress($task, 0, 'text.background_tasks.step.config_export_started');

        try {
            $total = max(1, count($sections));
            $done = 0;
            $package = $exporter->build($sections, $this->options, function (string $name) use ($recorder, $task, &$done, $total): void {
                $done++;
                $recorder->progress($task, (int) floor($done / $total * 90), 'text.background_tasks.step.config_export_section', ['done' => $done, 'total' => $total]);
            });

            $result = (new PackageValidator())->validate($package);
            if (!$result->isValid()) {
                throw new RuntimeException('The exported package did not pass validation.');
            }

            (new SecretGuard())->assertNoSecrets($package['sections']);

            $summary = $this->summary($package);
            $run->setPayload($package);
            $run->forceFill([
                'status' => ConfigPackageRun::STATUS_SUCCEEDED,
                'package_checksum' => $package['checksum'],
                'summary' => $summary,
            ])->save();

            $recorder->succeed($task, ['sections' => $summary['sections']]);

            $this->audit($run, $summary['sections']);
        } catch (Throwable $e) {
            $this->markFailed($run, $task, $recorder, $e);
        }
    }

    /**
     * Write the audit event (section names and counts only). A failing audit listener never undoes a finished export.
     *
     * @param array<string, int> $counts
     */
    private function audit(ConfigPackageRun $run, array $counts): void
    {
        try {
            event(new AuditLogEvent(
                'config.package.export',
                ['sections' => $counts],
                ConfigPackageRun::class,
                (int) $run->id,
                null,
                null,
                (int) ($run->created_by ?? 0),
            ));
        } catch (Throwable $e) {
            Log::warning('Config package export audit failed', ['run_id' => $run->id, 'exception' => $e::class]);
        }
    }

    /**
     * Values-free summary of a package: row counts per section, skipped counts and required secret keys.
     *
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>
     */
    private function summary(array $package): array
    {
        $counts = [];

        foreach ($package['sections'] as $name => $section) {
            $counts[$name] = $name === PackageFormat::TABLES_SECTION
                ? array_sum(array_map('count', $section))
                : count($section);
        }

        return [
            'sections' => $counts,
            'skipped' => $package['skipped'],
            'required_secrets' => ['count' => count($package['required_secrets']), 'keys' => $package['required_secrets']],
        ];
    }

    /**
     * Mark the run and task failed without keeping a payload or the exception message.
     */
    private function markFailed(ConfigPackageRun $run, BackgroundTask $task, BackgroundTaskRecorder $recorder, Throwable $e): void
    {
        $message = 'Export failed (' . class_basename($e) . ').';

        Log::error('Config package export failed', ['run_id' => $run->id, 'exception' => $e::class]);

        $run->setAttribute('payload', null);
        $run->forceFill([
            'status' => ConfigPackageRun::STATUS_FAILED,
            'package_checksum' => null,
            'summary' => ['error' => $message],
        ])->save();

        // The task message is an admin i18n key; the exception class stays in the log and the run summary only.
        $recorder->fail($task, 'text.background_tasks.step.config_export_failed');
    }
}
