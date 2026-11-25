<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Carbon\CarbonImmutable;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\DB;

/**
 * Remembers keys across runs of one project (table `workflow_dedupe_keys`, per project + `scope` + key hash, TTL).
 *
 * Modes:
 * - `filter`: keeps the items of `itemsExpr` whose key (`keyExpr`, evaluated with `item`) was not seen and claims
 *   them at once (an atomic insert per key, for `ttl_days`), so two overlapping runs never both get the same item.
 * - `check`: one key (`keyExpr`) → output `new` or `seen`.
 * - `commit`: marks the key(s) as seen for `ttl_days` (run it after the work succeeded).
 * Only key hashes are stored, never the key text.
 */
class DedupeRunner implements StepRunner
{
    use NodeHelpers;

    public const TABLE = 'workflow_dedupe_keys';
    public const HANDLES = ['new', 'seen'];
    protected const MAX_KEYS = 5000;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $mode = (string) ($config['mode'] ?? 'filter');
        $scope = substr(trim((string) ($config['scope'] ?? 'default')) ?: 'default', 0, 64);
        $project = $this->projectKey($step);

        if (!in_array($mode, ['filter', 'check', 'commit'], true)) {
            return StepRunResult::failed('dedupe: mode must be filter, check or commit');
        }
        if (!is_string($config['keyExpr'] ?? null) || trim($config['keyExpr']) === '') {
            return StepRunResult::failed('dedupe: keyExpr is required');
        }

        try {
            if ($mode === 'check') {
                $key = $this->keyOf($this->evalField($config, 'keyExpr', $context));
                if ($key === null) {
                    return StepRunResult::failed('dedupe: key must be a non-empty value');
                }
                $seen = $this->seenHashes($project, $scope, [$key]) !== [];

                return $this->routeOutput(
                    StepRunResult::success(['seen' => $seen]),
                    $step,
                    $seen ? 'seen' : 'new',
                    self::HANDLES,
                    $seen,
                );
            }

            $hasItems = is_string($config['itemsExpr'] ?? null) && trim($config['itemsExpr']) !== '';
            if (!$hasItems && $mode === 'filter') {
                return StepRunResult::failed('dedupe: itemsExpr is required for filter');
            }

            $items = $hasItems ? $this->toList($this->evalField($config, 'itemsExpr', $context)) : null;
            if ($hasItems && $items === null) {
                return StepRunResult::failed('dedupe: itemsExpr must resolve to a list');
            }
            if ($items !== null && count($items) > self::MAX_KEYS) {
                return StepRunResult::failed('dedupe: more than ' . self::MAX_KEYS . ' items');
            }

            $keyed = [];
            if ($items === null) {
                $key = $this->keyOf($this->evalField($config, 'keyExpr', $context));
                if ($key !== null) {
                    $keyed[] = [$key, null];
                }
            } else {
                foreach (array_values($items) as $item) {
                    $key = $this->keyOf($this->engine()->evaluate((string) $config['keyExpr'], $context, true, ['item' => $item]));
                    if ($key !== null) {
                        $keyed[] = [$key, $item];
                    }
                }
            }
        } catch (\Throwable $e) {
            return StepRunResult::failed('dedupe: ' . $e->getMessage());
        }

        if ($mode === 'commit') {
            $ttlDays = max(1, min(3650, (int) ($config['ttl_days'] ?? 30)));
            $count = $this->commit($project, $scope, array_unique(array_column($keyed, 0)), $ttlDays);

            return StepRunResult::success(['committed' => $count]);
        }

        $path = $this->storePath($config, 'vars.new_items');
        if ($path === null) {
            return StepRunResult::failed('dedupe: storeAs must start with vars.');
        }
        $seen = array_flip($this->seenHashes($project, $scope, array_column($keyed, 0)));
        $ttlDays = max(1, min(3650, (int) ($config['ttl_days'] ?? 30)));
        $fresh = [];
        $inBatch = [];
        foreach ($keyed as [$key, $item]) {
            $hash = hash('sha256', $key);
            if (isset($seen[$hash]) || isset($inBatch[$hash])) {
                continue;
            }
            $inBatch[$hash] = true;
            // Claim, do not just read: the run that inserts (or renews an expired) key owns the item (finding 30).
            if ($this->claim($project, $scope, $hash, $ttlDays)) {
                $fresh[] = $item;
            }
        }
        $context->setVarPath($path, $fresh);

        return StepRunResult::success(['total' => count($keyed), 'new' => count($fresh)]);
    }

    protected function keyOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        if (!is_scalar($value) || $value === '' || $value === false) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  list<string> $keys
     * @return list<string> hashes of the keys seen and not expired
     */
    protected function seenHashes(int $project, string $scope, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $hashes = array_values(array_unique(array_map(fn (string $k) => hash('sha256', $k), $keys)));
        $now = CarbonImmutable::now();
        $found = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $found = array_merge($found, DB::table(self::TABLE)
                ->where('project_id', $project)
                ->where('scope', $scope)
                ->whereIn('key_hash', $chunk)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->pluck('key_hash')
                ->all());
        }

        return $found;
    }

    /**
     * Take one key for this run: insert it, or renew it when it has expired. True when this run got it.
     */
    protected function claim(int $project, string $scope, string $hash, int $ttlDays): bool
    {
        $now = CarbonImmutable::now();
        $expires = $now->addDays($ttlDays);

        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'project_id' => $project,
            'scope' => $scope,
            'key_hash' => $hash,
            'expires_at' => $expires,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 1) {
            return true;
        }

        // The conditional update matches only an expired row, so of two runs only one renews it.
        return DB::table(self::TABLE)
            ->where('project_id', $project)
            ->where('scope', $scope)
            ->where('key_hash', $hash)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update(['expires_at' => $expires, 'updated_at' => $now]) === 1;
    }

    /**
     * @param array<int, string> $keys
     */
    protected function commit(int $project, string $scope, array $keys, int $ttlDays): int
    {
        $now = CarbonImmutable::now();
        $rows = array_map(fn (string $key) => [
            'project_id' => $project,
            'scope' => $scope,
            'key_hash' => hash('sha256', $key),
            'expires_at' => $now->addDays($ttlDays),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($keys));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(self::TABLE)->upsert($chunk, ['project_id', 'scope', 'key_hash'], ['expires_at', 'updated_at']);
        }

        // Keep the table small: drop this project's expired keys.
        DB::table(self::TABLE)->where('project_id', $project)->where('expires_at', '<=', $now)->limit(1000)->delete();

        return count($rows);
    }
}
