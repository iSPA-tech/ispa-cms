<?php

namespace App\Console\Commands;

use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTrackingEventReceiptsCommand extends Command
{
    protected $signature = 'cms:tracking-prune-receipts
                            {--limit=500 : Maximum terminal receipts to remove}
                            {--dry-run : Report eligible rows without deleting}
                            {--force : Required for mutation}';

    protected $description = 'Prune expired terminal tracking receipts after signed generation proof';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $now = CarbonImmutable::now('UTC');
        $eligible = TrackingEventReceipt::query()
            ->whereIn('status', [
                TrackingEventReceipt::STATUS_APPLIED,
                TrackingEventReceipt::STATUS_QUARANTINED,
            ])
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'write_generation', 'generation_mode', 'status', 'resolution_status']);

        $this->line("Expired terminal receipt candidates: {$eligible->count()}");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (!config('tracking.receipt_prune_enabled', false) || !$this->option('force')) {
            $this->error('Receipt pruning requires the enabled flag and --force.');

            return self::FAILURE;
        }

        if (TrackingEventReceipt::query()
            ->where('status', TrackingEventReceipt::STATUS_PENDING)
            ->exists()) {
            $this->error('Pending receipts block receipt pruning.');

            return self::FAILURE;
        }

        if (TrackingEventReceipt::query()
            ->where('status', TrackingEventReceipt::STATUS_QUARANTINED)
            ->where(function ($query): void {
                $query->whereNull('resolution_status')
                    ->orWhere('resolution_status', '!=', 'compensated');
            })
            ->exists()) {
            $this->error('Unresolved quarantine blocks receipt pruning.');

            return self::FAILURE;
        }

        foreach ($eligible->groupBy('write_generation') as $generation => $receipts) {
            $mode = (string) $receipts->first()->generation_mode;
            $signed = TrackingGenerationRun::query()
                ->where('closed_generation', $generation)
                ->where('closed_mode', $mode)
                ->whereIn('status', ['completed', 'released'])
                ->where('manifest_state', 'signed')
                ->whereNotNull('manifest_checksum')
                ->whereNotNull('completed_at')
                ->exists();

            if (!$signed) {
                $this->error("Generation {$generation} has no signed closed manifest.");

                return self::FAILURE;
            }
        }

        $ids = $eligible
            ->filter(static fn (TrackingEventReceipt $receipt): bool => (
                $receipt->status === TrackingEventReceipt::STATUS_APPLIED
                || $receipt->resolution_status === 'compensated'
            ))
            ->pluck('id')
            ->all();

        DB::transaction(static function () use ($ids): void {
            if ($ids !== []) {
                TrackingEventReceipt::query()->whereIn('id', $ids)->delete();
            }
        });

        $this->info('Pruned ' . count($ids) . ' tracking receipts.');

        return self::SUCCESS;
    }
}
