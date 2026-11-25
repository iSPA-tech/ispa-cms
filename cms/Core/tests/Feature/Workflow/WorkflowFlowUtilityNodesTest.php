<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Tests\Feature\Workflow\Concerns\BuildsWorkflowRuntimeTables;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Lane N6 flow and utility nodes, run through the executor where routing or persistence matters.
 */
class WorkflowFlowUtilityNodesTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        Schema::dropIfExists('workflow_dedupe_keys');
        Schema::dropIfExists('workflow_locks');
        (require __DIR__ . '/../../../database/migrations/2026_11_24_000030_create_workflow_dedupe_keys_and_locks_tables.php')->up();
        FlowRecordRunner::$seen = [];
        app(StepRunnerRegistry::class)->register('test_record', new FlowRecordRunner());
    }

    public function test_node_set_registers_every_lane_type(): void
    {
        $types = ['switch', 'split_in_batches', 'wait', 'render_template', 'text_transform', 'json_transform',
            'dedupe', 'lock', 'rate_limit', 'datetime', 'crypto'];
        $map = NodeRegistry::runnerMap();
        $registry = new NodeRegistry();

        foreach ($types as $type) {
            $this->assertArrayHasKey($type, $map, $type);
            $this->assertNotNull($registry->get($type), $type);
        }
        $this->assertSame(['internal_write'], $registry->get('dedupe')->safety);
        $this->assertSame(['acquired', 'busy'], $registry->get('lock')->outputs);
    }

    public function test_switch_follows_the_matching_case_and_falls_back_to_default(): void
    {
        foreach (['b' => 'b', 'zzz' => 'other'] as $value => $expected) {
            FlowRecordRunner::$seen = [];
            $project = WorkflowProject::create(['name' => 'Switch']);
            $switch = $this->makeStep($project, 'switch', 'sw', 1, [
                'valueExpr' => 'vars.kind',
                'cases' => [['key' => 'a', 'match' => 'a'], ['key' => 'b', 'match' => ['b', 'bb']]],
            ]);
            $this->edge($project, $switch, $this->makeStep($project, 'test_record', 'a', 2), 'case:a');
            $this->edge($project, $switch, $this->makeStep($project, 'test_record', 'b', 3), 'case:b');
            $this->edge($project, $switch, $this->makeStep($project, 'test_record', 'other', 4), 'default');

            $this->assertSame('completed', $this->runFlow($project, ['kind' => $value])->status);
            $this->assertSame([$expected], FlowRecordRunner::$seen);
        }
    }

    public function test_decision_condition_rows_route_true_and_false(): void
    {
        $project = WorkflowProject::create(['name' => 'Decision']);
        $decision = $this->makeStep($project, 'decision', 'd', 1, [
            'combinator' => 'and',
            'conditions' => [
                ['left' => 'vars.score', 'operator' => 'gte', 'right' => 7],
                ['left' => 'vars.lang', 'operator' => 'in', 'right' => ['vi', 'en']],
            ],
        ]);
        $this->edge($project, $decision, $this->makeStep($project, 'test_record', 'yes', 2), 'true');
        $this->edge($project, $decision, $this->makeStep($project, 'test_record', 'no', 3), 'false');

        $this->runFlow($project, ['score' => 8, 'lang' => 'vi']);
        $this->runFlow($project, ['score' => 8, 'lang' => 'fr']);

        $this->assertSame(['yes', 'no'], FlowRecordRunner::$seen);
    }

    public function test_invalid_json_with_only_a_valid_edge_ends_the_run_instead_of_taking_it(): void
    {
        $project = WorkflowProject::create(['name' => 'Validate']);
        $check = $this->makeStep($project, 'json_transform', 'check', 1, [
            'op' => 'validate', 'inputExpr' => 'vars.payload', 'storeAs' => 'vars.check',
            'schema' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]],
        ]);
        $this->edge($project, $check, $this->makeStep($project, 'test_record', 'publish', 2), 'valid');

        $instance = $this->runFlow($project, ['payload' => ['body' => 'x']]);
        $this->assertSame('completed', $instance->status);
        $this->assertSame([], FlowRecordRunner::$seen);

        $this->runFlow($project, ['payload' => ['title' => 'ok']]);
        $this->assertSame(['publish'], FlowRecordRunner::$seen);
    }

    public function test_dedupe_filters_seen_keys_only_after_commit_and_per_project(): void
    {
        $project = WorkflowProject::create(['name' => 'Dedupe']);
        $filter = $this->makeStep($project, 'dedupe', 'filter', 1, [
            'mode' => 'filter', 'itemsExpr' => 'vars.items', 'keyExpr' => 'item["url"]', 'storeAs' => 'vars.fresh',
        ]);
        $record = $this->makeStep($project, 'test_record', 'record', 2, ['var' => 'fresh']);
        $commit = $this->makeStep($project, 'dedupe', 'commit', 3, [
            'mode' => 'commit', 'itemsExpr' => 'vars.fresh', 'keyExpr' => 'item["url"]', 'ttl_days' => 30,
        ]);
        $this->edge($project, $filter, $record, null);
        $this->edge($project, $record, $commit, null);

        $items = [['url' => 'a'], ['url' => 'b'], ['url' => 'a']];
        $this->runFlow($project, ['items' => $items]);
        $this->runFlow($project, ['items' => array_merge($items, [['url' => 'c']])]);

        $this->assertSame([[['url' => 'a'], ['url' => 'b']], [['url' => 'c']]], FlowRecordRunner::$seen);
        $this->assertSame(3, DB::table('workflow_dedupe_keys')->where('project_id', $project->id)->count());
        $this->assertSame(0, DB::table('workflow_dedupe_keys')->where('key_hash', 'a')->count(), 'only hashes are stored');

        DB::table('workflow_dedupe_keys')->update(['expires_at' => now()->subDay()]);
        FlowRecordRunner::$seen = [];
        $this->runFlow($project, ['items' => [['url' => 'a']]]);
        $this->assertSame([[['url' => 'a']]], FlowRecordRunner::$seen, 'expired keys count as new');
    }

    /** Review finding 30: two runs filtering the same key before either commits must not both get it. */
    public function test_dedupe_filter_claims_its_keys_so_an_overlapping_run_gets_none(): void
    {
        $project = WorkflowProject::create(['name' => 'Dedupe claim']);
        $filter = $this->makeStep($project, 'dedupe', 'filter', 1, [
            'mode' => 'filter', 'itemsExpr' => 'vars.items', 'keyExpr' => 'item["url"]', 'storeAs' => 'vars.fresh',
        ]);
        $this->edge($project, $filter, $this->makeStep($project, 'test_record', 'record', 2, ['var' => 'fresh']), null);

        // No commit step: the first run is still busy with its items when the second one filters.
        $this->runFlow($project, ['items' => [['url' => 'a'], ['url' => 'b']]]);
        $this->runFlow($project, ['items' => [['url' => 'a'], ['url' => 'b']]]);

        $this->assertSame([[['url' => 'a'], ['url' => 'b']], []], FlowRecordRunner::$seen);
    }

    public function test_dedupe_check_routes_seen_and_ends_without_a_seen_edge(): void
    {
        $project = WorkflowProject::create(['name' => 'Check']);
        $check = $this->makeStep($project, 'dedupe', 'check', 1, ['mode' => 'check', 'keyExpr' => 'vars.key']);
        $work = $this->makeStep($project, 'test_record', 'work', 2);
        $commit = $this->makeStep($project, 'dedupe', 'commit', 3, ['mode' => 'commit', 'keyExpr' => 'vars.key']);
        $this->edge($project, $check, $work, 'new');
        $this->edge($project, $work, $commit, null);

        $this->runFlow($project, ['key' => 'k1']);
        $this->runFlow($project, ['key' => 'k1']);

        $this->assertSame(['work'], FlowRecordRunner::$seen);
    }

    public function test_lock_is_busy_while_the_owner_runs_and_free_once_it_finished(): void
    {
        $project = WorkflowProject::create(['name' => 'Lock']);
        $lock = $this->makeStep($project, 'lock', 'lock', 1, ['keyExpr' => 'vars.key', 'ttl_seconds' => 600, 'on_busy' => 'skip']);
        $this->edge($project, $lock, $this->makeStep($project, 'test_record', 'work', 2), 'acquired');

        $holder = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'awaiting_approval']);
        DB::table('workflow_locks')->insert([
            'project_id' => $project->id, 'lock_key' => hash('sha256', 'place-1'),
            'owner_instance_id' => $holder->id, 'expires_at' => now()->addHour(),
        ]);

        $busy = $this->runFlow($project, ['key' => 'place-1']);
        $this->assertSame('completed', $busy->status);
        $this->assertSame([], FlowRecordRunner::$seen, 'busy must not take the acquired edge');

        $holder->update(['status' => 'completed', 'finished_at' => now()]);
        $second = $this->runFlow($project, ['key' => 'place-1']);
        $this->assertSame(['work'], FlowRecordRunner::$seen);
        $this->assertSame($second->id, (int) DB::table('workflow_locks')->value('owner_instance_id'));
    }

    /**
     * Review finding 36: the owner takes its lock again within the same second (MySQL reports 0 changed rows for an
     * identical update; reproduced against MySQL 9 with a scratch script, SQLite counts matched rows).
     */
    public function test_lock_owner_takes_its_lock_again_in_the_same_second(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-11-25 10:00:00');
        $owner = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => WorkflowProject::create(['name' => 'Owner'])->id, 'status' => 'running']);
        $runner = new \Cms\Core\Workflows\Runtime\Runners\Util\LockRunner();
        $acquire = new \ReflectionMethod($runner, 'acquire');

        $this->assertTrue($acquire->invoke($runner, 1, hash('sha256', 'k'), $owner->id, 600));
        $this->assertTrue($acquire->invoke($runner, 1, hash('sha256', 'k'), $owner->id, 600));
        \Carbon\CarbonImmutable::setTestNow();
    }

    public function test_lock_on_busy_fail_fails_the_step_and_release_frees_it(): void
    {
        $project = WorkflowProject::create(['name' => 'Lock fail']);
        $this->makeStep($project, 'lock', 'lock', 1, ['keyExpr' => 'vars.key', 'on_busy' => 'fail']);
        $holder = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'running']);
        DB::table('workflow_locks')->insert([
            'project_id' => $project->id, 'lock_key' => hash('sha256', 'x'),
            'owner_instance_id' => $holder->id, 'expires_at' => now()->addHour(),
        ]);
        $this->assertSame('failed', $this->runFlow($project, ['key' => 'x'])->status);

        $other = WorkflowProject::create(['name' => 'Lock release']);
        $take = $this->makeStep($other, 'lock', 'take', 1, ['keyExpr' => 'vars.key']);
        $release = $this->makeStep($other, 'lock', 'release', 2, ['keyExpr' => 'vars.key', 'mode' => 'release']);
        $this->edge($other, $take, $release, 'acquired');
        $this->runFlow($other, ['key' => 'x']);
        $this->assertSame(0, DB::table('workflow_locks')->where('project_id', $other->id)->count());
    }

    public function test_rate_limit_skip_leaves_by_limited_after_max_passes(): void
    {
        $project = WorkflowProject::create(['name' => 'Rate']);
        $rate = $this->makeStep($project, 'rate_limit', 'rate', 1, ['keyExpr' => "'fb'", 'max' => 2, 'per_seconds' => 3600, 'on_limit' => 'skip']);
        $this->edge($project, $rate, $this->makeStep($project, 'test_record', 'ok', 2), 'ok');
        $this->edge($project, $rate, $this->makeStep($project, 'test_record', 'limited', 3), 'limited');

        foreach (range(1, 3) as $i) {
            $this->runFlow($project, []);
        }

        $this->assertSame(['ok', 'ok', 'limited'], FlowRecordRunner::$seen);
    }

    public function test_rate_limit_wait_reschedules_into_the_next_window(): void
    {
        Queue::fake();
        $project = WorkflowProject::create(['name' => 'Rate wait']);
        $rate = $this->makeStep($project, 'rate_limit', 'rate', 1, ['max' => 1, 'per_seconds' => 3600, 'on_limit' => 'wait']);
        $after = $this->makeStep($project, 'test_record', 'after', 2);
        $this->edge($project, $rate, $after, 'ok');

        $this->runFlow($project, [], true);
        $second = $this->runFlow($project, [], true);

        $this->assertSame(['after'], FlowRecordRunner::$seen);
        $this->assertSame('waiting', $second->status);
        $this->assertSame($after->id, (int) $second->resume_step_id);
        Queue::assertPushed(RunWorkflowInstanceJob::class, fn ($job) => $job->workflowInstanceId() === $second->id && $job->delay !== null);
    }

    public function test_split_in_batches_runs_the_body_per_batch_then_done(): void
    {
        $project = WorkflowProject::create(['name' => 'Batches']);
        $split = $this->makeStep($project, 'split_in_batches', 'split', 1, ['collectionExpr' => 'vars.items', 'batch_size' => 2, 'storeAs' => 'vars.batch']);
        $body = $this->makeStep($project, 'test_record', 'body', 2, ['var' => 'batch']);
        $end = $this->makeStep($project, 'test_record', 'end', 3);
        $this->edge($project, $split, $body, 'batch');
        $this->edge($project, $body, $split, null);
        $this->edge($project, $split, $end, 'done');

        $this->assertSame('completed', $this->runFlow($project, ['items' => [1, 2, 3, 4, 5]])->status);
        $this->assertSame([[1, 2], [3, 4], [5], 'end'], FlowRecordRunner::$seen);
    }

    public function test_wait_until_a_future_time_reschedules_and_a_past_time_continues(): void
    {
        Queue::fake();
        $project = WorkflowProject::create(['name' => 'Wait']);
        $wait = $this->makeStep($project, 'wait', 'wait', 1, ['mode' => 'until', 'untilExpr' => 'vars.at']);
        $after = $this->makeStep($project, 'test_record', 'after', 2);
        $this->edge($project, $wait, $after, null);

        $future = $this->runFlow($project, ['at' => now()->addHours(2)->toIso8601String()], true);
        $this->assertSame('waiting', $future->status);
        $this->assertSame([], FlowRecordRunner::$seen);

        $past = $this->runFlow($project, ['at' => now()->subHour()->toIso8601String()], true);
        $this->assertSame('completed', $past->status);
        $this->assertSame(['after'], FlowRecordRunner::$seen);
    }

    protected function runFlow(WorkflowProject $project, array $vars, bool $queued = false): WorkflowInstance
    {
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'pending',
            'context_snapshot' => ['trigger' => [], 'vars' => $vars, 'steps' => []],
        ]);
        app(WorkflowGraphExecutor::class)->execute($instance->id, $queued);

        return $instance->fresh();
    }

    protected function edge(WorkflowProject $project, WorkflowStep $from, WorkflowStep $to, ?string $handle): void
    {
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $from->id,
            'to_step_id' => $to->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }
}

class FlowRecordRunner implements StepRunner
{
    /** @var array<int, mixed> */
    public static array $seen = [];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $var = $step->stepConfig['var'] ?? null;
        self::$seen[] = is_string($var) ? $context->getVarPath($var) : $step->stepCode;

        return StepRunResult::success();
    }
}
