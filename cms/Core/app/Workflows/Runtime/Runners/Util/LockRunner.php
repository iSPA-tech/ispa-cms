<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Carbon\CarbonImmutable;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Named lock per project across runs (table `workflow_locks`), owned by the workflow instance.
 *
 * `mode=acquire` (default): takes `keyExpr` for `ttl_seconds`; output `acquired`, or when another live run holds it,
 * `busy` (`on_busy=skip`) or a failed step (`on_busy=fail`). Taking is one atomic INSERT-or-conditional-UPDATE.
 * A lock is free again as soon as its owner run has finished (completed, failed, cancelled or terminated: its
 * `finished_at` is set or the instance is gone), when its TTL has passed, or on `mode=release` by the owner.
 * The same run may take its own lock again (e.g. inside a loop).
 */
class LockRunner implements StepRunner
{
    use NodeHelpers;

    public const TABLE = 'workflow_locks';
    public const HANDLES = ['acquired', 'busy'];
    protected const DONE_STATUSES = ['completed', 'failed', 'cancelled'];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $mode = (string) ($config['mode'] ?? 'acquire');
        $onBusy = (string) ($config['on_busy'] ?? 'skip');
        $owner = $this->instanceId($context);

        if (!in_array($mode, ['acquire', 'release'], true) || !in_array($onBusy, ['skip', 'fail'], true)) {
            return StepRunResult::failed('lock: mode must be acquire|release and on_busy skip|fail');
        }
        if ($owner === null) {
            return StepRunResult::failed('lock: needs a workflow run');
        }

        try {
            $key = $this->evalField($config, 'keyExpr', $context);
        } catch (\Throwable $e) {
            return StepRunResult::failed('lock: ' . $e->getMessage());
        }
        if (!is_scalar($key) || (string) $key === '') {
            return StepRunResult::failed('lock: keyExpr must resolve to a non-empty value');
        }
        $lockKey = hash('sha256', (string) $key);
        $project = $this->projectKey($step);

        if ($mode === 'release') {
            $released = DB::table(self::TABLE)->where('project_id', $project)->where('lock_key', $lockKey)
                ->where('owner_instance_id', $owner)->delete();

            return StepRunResult::success(['released' => $released > 0]);
        }

        $ttl = max(1, min(86400, (int) ($config['ttl_seconds'] ?? 600)));
        $acquired = $this->acquire($project, $lockKey, $owner, $ttl);

        if ($acquired) {
            return $this->routeOutput(StepRunResult::success(['acquired' => true]), $step, 'acquired', self::HANDLES, false);
        }
        if ($onBusy === 'fail') {
            return StepRunResult::failed('lock: busy (held by another run)', [], ['code' => 'lock_busy']);
        }

        return $this->routeOutput(StepRunResult::success(['acquired' => false]), $step, 'busy', self::HANDLES, true);
    }

    protected function acquire(int $project, string $lockKey, int $owner, int $ttl): bool
    {
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds($ttl);

        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'project_id' => $project,
            'lock_key' => $lockKey,
            'owner_instance_id' => $owner,
            'expires_at' => $expires,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 1) {
            return true;
        }

        $instances = Tables::WORKFLOW_INSTANCE;
        $updated = DB::table(self::TABLE)
            ->where('project_id', $project)
            ->where('lock_key', $lockKey)
            ->where(function (Builder $q) use ($owner, $now, $instances): void {
                $q->where('owner_instance_id', $owner)
                    ->orWhere('expires_at', '<=', $now)
                    ->orWhereNull('owner_instance_id')
                    ->orWhereNotExists(function (Builder $live) use ($instances): void {
                        $live->selectRaw('1')->from($instances)
                            ->whereColumn($instances . '.id', self::TABLE . '.owner_instance_id')
                            ->whereNull($instances . '.finished_at')
                            ->whereNotIn($instances . '.status', self::DONE_STATUSES);
                    });
            })
            ->update(['owner_instance_id' => $owner, 'expires_at' => $expires, 'updated_at' => $now]);
        if ($updated === 1) {
            return true;
        }

        // MySQL/MariaDB count changed rows, not matched rows: the owner taking its lock again within the same second
        // writes identical values and gets 0. Whoever owns the row now holds the lock.
        return (int) DB::table(self::TABLE)->where('project_id', $project)->where('lock_key', $lockKey)
            ->value('owner_instance_id') === $owner;
    }
}
