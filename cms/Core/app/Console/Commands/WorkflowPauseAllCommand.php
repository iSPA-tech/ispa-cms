<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Illuminate\Console\Command;

/**
 * Pause or resume every workflow of the site from the server (guardrails B7, incident response).
 *
 * Usage: php artisan cms:workflow-pause-all on|off
 * Same switch as the Webmaster's admin toggle (setting `workflow.paused_all`), audited and alerted. With no argument it
 * prints the current state. While `WORKFLOW_PAUSED_ALL=true` is set in the environment, `off` only clears the setting:
 * the server switch keeps everything paused.
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowPauseAllCommand extends Command
{
    protected $signature = 'cms:workflow-pause-all {state? : on or off}';

    protected $description = 'Pause or resume every workflow of the site.';

    public function handle(WorkflowGlobalPause $pause): int
    {
        $state = strtolower((string) $this->argument('state'));

        if ($state === '') {
            $this->line('Workflows are ' . ($pause->active() ? 'PAUSED' : 'running') . ($pause->forcedByServer() ? ' (WORKFLOW_PAUSED_ALL is set).' : '.'));

            return self::SUCCESS;
        }
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('Use on or off.');

            return self::INVALID;
        }

        $changed = $pause->set($state === 'on', null, 'command');
        $this->info($changed ? "Pause all workflows: {$state}." : "Pause all workflows was already {$state}.");
        if ($state === 'off' && $pause->forcedByServer()) {
            $this->warn('WORKFLOW_PAUSED_ALL is set on the server: workflows stay paused until it is removed.');
        }

        return self::SUCCESS;
    }
}
