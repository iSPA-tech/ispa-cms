<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a starter pack queued by {@see StarterPackApplier::queue()} (background task type `core.starter_pack.apply`,
 * module `Core`).
 *
 * Installing or enabling a module restarts the queue workers, and this process does not know the new module's code.
 * So the applier stops after a modules step that changed something ({@see StarterPackApplier::OPTION_YIELD}) and this
 * job queues a fresh job (`hop` + 1, delayed) that continues from the next step; nothing waits for the old job to come
 * back after the restart. A continuation that still runs in an old worker (a content importer of the pack is not
 * loaded, or a module the run enabled still reads as disabled) waits again, at most MAX_HOPS times; the last attempt
 * fails with a plain message instead.
 *
 * `tries = 1`: an apply is never handed to a second worker; a killed or timed-out run is closed by failed().
 */
final class ApplyStarterPackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Background task type. */
    public const TASK_TYPE = 'core.starter_pack.apply';

    /** Background task module. */
    public const TASK_MODULE = 'Core';

    /** Seconds a continuation waits, so the workers can restart first. */
    public const CONTINUE_DELAY_SECONDS = 30;

    /** Maximum number of continuations of one application. */
    public const MAX_HOPS = 4;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param string               $applicationUuid Ledger entry to run.
     * @param string               $packDir         Pack folder (loaded again in the worker).
     * @param array<string, mixed> $options         Applier options (confirm_irreversible).
     * @param int                  $hop             0 for the first job, then one more per continuation.
     */
    public function __construct(
        public readonly string $applicationUuid,
        public readonly string $packDir,
        public readonly array $options = [],
        public readonly int $hop = 0,
    ) {
        $this->timeout = (int) config('core.module_packages.job_timeout', 2700);
    }

    /**
     * Run (or continue) the apply; a duplicate delivery of a finished application does nothing.
     */
    public function handle(StarterPackApplier $applier, StarterPackLoader $loader): void
    {
        $application = $this->application();
        if ($application === null || $application->status !== StarterPackApplication::STATUS_STARTED) {
            return;
        }

        try {
            $pack = $loader->load($this->packDir);
        } catch (StarterPackException) {
            $applier->stop($application, 'The pack could not be read any more; apply it again.');

            return;
        }

        if ($pack->key !== $application->pack_key || $pack->version !== $application->pack_version) {
            $applier->stop($application, 'The pack changed after the apply was started; apply it again.');

            return;
        }

        if ($this->hop > 0 && $this->hop < self::MAX_HOPS && $applier->missingImporters($pack) !== []) {
            $this->continueLater();

            return;
        }

        $application = $applier->apply($pack, $application->applied_by, $this->options + [
            StarterPackApplier::OPTION_YIELD => true,
            StarterPackApplier::OPTION_LAST_ATTEMPT => $this->hop >= self::MAX_HOPS,
        ], $application);

        if ($application->status === StarterPackApplication::STATUS_STARTED && $this->hop < self::MAX_HOPS) {
            $this->continueLater();
        }
    }

    /**
     * After a worker kill or timeout: close the ledger entry and its task.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Starter pack apply job failed', ['application' => $this->applicationUuid, 'exception' => $e::class]);

        $application = $this->application();
        if ($application !== null) {
            app(StarterPackApplier::class)->stop($application, 'The starter pack apply stopped unexpectedly; apply the pack again.');
        }
    }

    /**
     * Queue the next job for the same application, after the workers had time to restart.
     */
    private function continueLater(): void
    {
        $next = new self($this->applicationUuid, $this->packDir, $this->options, $this->hop + 1);
        if ($this->queue !== null) {
            $next->onQueue($this->queue);
        }

        dispatch($next->delay(now()->addSeconds(self::CONTINUE_DELAY_SECONDS)));
    }

    /**
     * The ledger entry of this job.
     */
    private function application(): ?StarterPackApplication
    {
        return StarterPackApplication::query()->where('uuid', $this->applicationUuid)->first();
    }
}
