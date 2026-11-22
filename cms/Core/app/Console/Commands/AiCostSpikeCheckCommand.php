<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Ai\Usage\AiCostSpikeDetector;
use Illuminate\Console\Command;

/**
 * Scheduled check that alerts (once per UTC day) when today's AI cost spikes
 * above the recent daily average. See AiCostSpikeDetector for the rule.
 */
class AiCostSpikeCheckCommand extends Command
{
    protected $signature = 'cms:ai-cost-spike-check {--dry-run : Evaluate and print without sending an alert}';

    protected $description = 'Alert when today\'s AI cost spikes above the recent daily average.';

    /**
     * Run the check and print the evaluation.
     *
     * @param AiCostSpikeDetector $detector Spike detector.
     * @return int Command exit code.
     */
    public function handle(AiCostSpikeDetector $detector): int
    {
        $result = $this->option('dry-run') ? $detector->evaluate() + ['alerted' => false] : $detector->alertIfSpiking();

        if (! isset($result['date'])) {
            $this->warn('AI cost spike check failed; see the application log.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s UTC: today %.4f USD, baseline %.4f USD/day, threshold %.4f USD, spike %s, alerted %s%s',
            $result['date'],
            $result['today_usd'],
            $result['baseline_usd'],
            $result['threshold_usd'],
            $result['spike'] ? 'yes' : 'no',
            $result['alerted'] ? 'yes' : 'no',
            $result['enabled'] ? '' : ' (disabled)',
        ));

        return self::SUCCESS;
    }
}
