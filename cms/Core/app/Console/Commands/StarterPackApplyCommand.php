<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Models\User;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Illuminate\Console\Command;

/**
 * Developer entry point of the starter packs: prints the plan, and with no `--dry-run` applies the pack.
 *
 * Without `--sync` the apply is queued as one tracked background task (the task uuid is printed). Installing or
 * enabling a module hands the remaining steps to a fresh job after the queue workers restarted; `--wait` polls the
 * ledger until that has finished. `--sync` runs every step in this process (tests and scratch sites).
 */
class StarterPackApplyCommand extends Command
{
    protected $signature = 'cms:starter-pack-apply
        {key : Pack key}
        {--dry-run : Print the plan only; nothing is written}
        {--status=draft : Status of the sample content (draft or published)}
        {--sync : Run in this process instead of the queue}
        {--user= : Webmaster id or e-mail that applies the pack (required when the pack carries a configuration)}
        {--confirm-irreversible : Confirm modules whose database changes cannot be undone}
        {--confirm-third-party : Confirm modules published by a partner, not by iSPA, that run with full rights on this site}
        {--wait : Without --sync, wait until the queued apply has finished}
        {--timeout=1800 : Seconds --wait polls at most}
        {--interval=3 : Seconds between two polls of --wait}
        {--path= : Folder with one sub-folder per pack (default: cms/Core/resources/starter-packs)}';

    protected $description = 'Plan, dry-run or apply a starter pack on this site.';

    /**
     * Run the command.
     *
     * @return int 0 on success (or a queued apply), 1 on a blocker, a bad option or a failed apply.
     */
    public function handle(StarterPackPlanner $planner, StarterPackApplier $applier): int
    {
        $status = (string) $this->option('status');
        if (!in_array($status, ['draft', 'published'], true)) {
            $this->error('--status must be draft or published.');

            return 1;
        }

        $pack = $this->loadPack((string) $this->argument('key'));
        if ($pack === null) {
            return 1;
        }

        $userId = $this->resolveUser($pack);
        if ($userId === false) {
            return 1;
        }

        $plan = $planner->plan($pack, $userId);
        $this->printPlan($plan);

        if (!$plan->canApply()) {
            $this->error('The pack cannot be applied:');
            foreach ($plan->blockers as $blocker) {
                $this->line('  - ' . $blocker);
            }

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return 0;
        }

        $options = [StarterPackApplier::OPTION_CONTENT_STATUS => $status];
        if ($this->option('confirm-third-party')) {
            $options[StarterPackApplier::OPTION_CONFIRM_THIRD_PARTY] = true;
        }
        if ($this->option('confirm-irreversible')) {
            $options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE] = true;
        } else {
            foreach ($plan->modules as $module) {
                if ($module['state'] === StarterPackPlan::STATE_TO_INSTALL && $module['irreversible'] !== []) {
                    $this->error("Module {$module['name']} has database changes that cannot be undone ("
                        . implode('; ', $module['irreversible']) . '); repeat with --confirm-irreversible to apply the pack.');

                    return 1;
                }
            }
        }

        try {
            if ($this->option('sync')) {
                return $this->report($applier->apply($pack, $userId, $options));
            }

            $application = $applier->queue($pack, $userId, $options);
        } catch (StarterPackException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info(sprintf('Apply queued. Task %s, application %s.', $application->background_task_uuid, $application->uuid));

        return $this->option('wait') ? $this->wait($application) : 0;
    }

    /**
     * Load the pack, or list the available keys.
     */
    private function loadPack(string $key): ?StarterPackManifest
    {
        $root = $this->option('path') ?: null;
        $loader = new StarterPackLoader(is_string($root) ? $root : null);

        try {
            $packs = $loader->all();
        } catch (StarterPackException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if (!isset($packs[$key])) {
            $this->error(sprintf('Unknown starter pack "%s". Available: %s.', $key, $packs === [] ? 'none' : implode(', ', array_keys($packs))));

            return null;
        }

        return $packs[$key];
    }

    /**
     * Acting user id: null (no option, pack without configuration) or the Webmaster's id; false after an error.
     */
    private function resolveUser(StarterPackManifest $pack): int|false|null
    {
        $reference = trim((string) $this->option('user'));
        if ($reference === '') {
            if ($pack->configPath() !== null) {
                $this->error('This pack carries a configuration, which only a Webmaster can apply: pass --user=<id or e-mail>.');

                return false;
            }

            return null;
        }

        $user = ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : User::query()->where('email', $reference)->first();
        if ($user === null) {
            $this->error(sprintf('No user found for "%s".', $reference));

            return false;
        }

        if ($pack->configPath() !== null && !$user->isWebmaster()) {
            $this->error(sprintf('User "%s" is not a Webmaster; only a Webmaster can apply the configuration of this pack.', $reference));

            return false;
        }

        return (int) $user->getKey();
    }

    /**
     * Print the plan as tables.
     */
    private function printPlan(StarterPackPlan $plan): void
    {
        $this->line(sprintf('Starter pack %s %s', $plan->packKey, $plan->packVersion));

        $rows = [];
        foreach ($plan->modules as $module) {
            $rows[] = ['module', $module['name'], $module['state'], $module['version'] ?? '-'];
        }
        foreach ((array) ($plan->config['sections'] ?? []) as $name => $section) {
            $counts = (array) ($section['counts'] ?? []);
            $rows[] = ['config', (string) $name, (string) ($section['status'] ?? '-'), sprintf('create %d, update %d', (int) ($counts['create'] ?? 0), (int) ($counts['update'] ?? 0))];
        }
        if ($plan->roles !== null) {
            $rows[] = ['roles', 'add', count($plan->roles['created']) . ' new', count($plan->roles['skipped']) . ' kept'];
        }
        if ($plan->workflows !== null) {
            $rows[] = ['workflows', 'add', count($plan->workflows['created']) . ' new', count($plan->workflows['skipped']) . ' kept'];
        }
        foreach ($plan->content as $entry) {
            $rows[] = ['content', $entry['importer'], $entry['counts'] === null ? 'after module enable' : 'ready', $entry['counts'] === null ? '-' : json_encode($entry['counts'])];
        }

        $this->table(['Area', 'Name', 'State', 'Detail'], $rows);

        foreach ($plan->warnings as $warning) {
            $this->line('<fg=yellow>warning</> ' . $warning);
        }
    }

    /**
     * Print the steps of an application and the outcome.
     *
     * @return int 0 when it succeeded, 1 otherwise.
     */
    private function report(StarterPackApplication $application): int
    {
        $rows = [];
        foreach ((array) $application->steps as $step) {
            $rows[] = [(string) ($step['name'] ?? ''), (string) ($step['status'] ?? ''), (string) ($step['message'] ?? '')];
        }
        $this->table(['Step', 'Status', 'Message'], $rows);
        $this->line(sprintf('Starter pack %s %s: %s', $application->pack_key, $application->pack_version, $application->status));

        return $application->status === StarterPackApplication::STATUS_SUCCEEDED ? 0 : 1;
    }

    /**
     * Poll the ledger entry until it left `started` (a module change hands the rest to a fresh job) or time ran out.
     */
    private function wait(StarterPackApplication $application): int
    {
        $deadline = time() + max(0, (int) $this->option('timeout'));
        $interval = max(0, (int) $this->option('interval'));
        $this->line('Waiting for the apply to finish (module changes hand the remaining steps to a fresh job)...');

        while ($application->refresh()->status === StarterPackApplication::STATUS_STARTED) {
            if (time() >= $deadline) {
                $this->error(sprintf('Still running after the timeout; check application %s later.', $application->uuid));

                return 1;
            }
            if ($interval > 0) {
                sleep($interval);
            }
        }

        return $this->report($application);
    }
}
