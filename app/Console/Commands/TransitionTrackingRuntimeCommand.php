<?php

namespace App\Console\Commands;

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TransitionTrackingRuntimeCommand extends Command
{
    protected $signature = 'cms:tracking-transition
                            {--to= : Approved target mode}
                            {--expected-generation= : Current generation guard}
                            {--effective-date= : First direct-write local date}
                            {--process-contract-version= : Verified deployed producer/worker contract}
                            {--backup-id= : Consistent pre-transition backup proof}
                            {--operator= : Named operator}
                            {--reason= : Change reason}
                            {--approve : Explicitly authorize the transition}';

    protected $description = 'Operator-gated one-way transition from legacy to dual tracking';

    public function handle(): int
    {
        if (!config('tracking.mode_transition_enabled', false) || !$this->option('approve')) {
            $this->error('Tracking mode transition requires its enabled flag and --approve.');

            return self::FAILURE;
        }

        $lock = Cache::lock(
            (string) config('tracking.finalizer.lock', 'tracking:finalizer'),
            300
        );
        if (!$lock->get()) {
            $this->error('Tracking finalizer or cutover is already running.');

            return self::FAILURE;
        }

        try {
            $this->assertPreconditions();
            DB::transaction(function (): void {
                $state = TrackingRuntimeState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                $expected = (int) $this->option('expected-generation');
                if (
                    $state->mode !== TrackingRuntimeState::MODE_LEGACY
                    || $state->processing_state !== TrackingRuntimeState::PROCESSING_OPEN
                    || $state->direct_counter_owner !== 'legacy'
                    || (int) $state->write_generation !== $expected
                ) {
                    throw new RuntimeException('Runtime state changed before dual activation.');
                }
                if (TrackingEventReceipt::query()
                    ->where('write_generation', $expected)
                    ->where('status', TrackingEventReceipt::STATUS_PENDING)
                    ->exists()) {
                    throw new RuntimeException('Pending legacy receipts block dual activation.');
                }

                $state->forceFill([
                    'write_generation' => $expected + 1,
                    'mode' => TrackingRuntimeState::MODE_DUAL,
                    'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
                    'direct_counter_owner' => 'event',
                    'first_direct_write_date' => $this->option('effective-date'),
                    'generation_opened_at' => CarbonImmutable::now('UTC'),
                    'changed_by' => trim((string) $this->option('operator')),
                    'change_reason' => trim((string) $this->option('reason')),
                ])->save();
            });

            $state = TrackingRuntimeState::query()->findOrFail(1);
            $this->line(implode(' ', [
                "mode={$state->mode}",
                "generation={$state->write_generation}",
                'first_direct_write_date=' . $state->first_direct_write_date->toDateString(),
                'read_mode=' . config('tracking.read_mode', 'raw'),
            ]));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function assertPreconditions(): void
    {
        if ((string) $this->option('to') !== TrackingRuntimeState::MODE_DUAL) {
            throw new RuntimeException('Only the approved legacy-to-dual transition is supported.');
        }
        foreach (['expected-generation', 'effective-date', 'process-contract-version', 'backup-id', 'operator', 'reason'] as $option) {
            if (trim((string) $this->option($option)) === '') {
                throw new RuntimeException("--{$option} is required.");
            }
        }
        if (
            (int) $this->option('process-contract-version')
                !== (int) config('tracking.aggregate_contract_version', 1)
        ) {
            throw new RuntimeException('Deployed process contract proof does not match.');
        }

        $today = CarbonImmutable::now((string) config('tracking.analytics_timezone'))
            ->toDateString();
        if (CarbonImmutable::parse((string) $this->option('effective-date'))->toDateString() !== $today) {
            throw new RuntimeException('Dual activation date must be the current local analytics date.');
        }
        if (
            !Schema::hasIndex(Tables::TRACKING_DAILY, ['date'], 'unique')
            || !Schema::hasIndex(
                Tables::TRACKING_TARGET_DAILY,
                ['date', 'target_key'],
                'unique'
            )
        ) {
            throw new RuntimeException('Final aggregate uniqueness constraints are required.');
        }
    }
}
