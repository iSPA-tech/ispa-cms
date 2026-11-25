<?php

namespace Cms\Core\Tests\Unit\Services;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\WorkflowExecutor;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class WorkflowExecutorTest extends TestCase
{
    protected DummyLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new DummyLogger();
        CMSLogger::swap($this->logger);
    }

    public function test_run_executes_steps_and_logs_start_and_steps(): void
    {
        $workflow = $this->makeWorkflowWithSteps([
            [
                'id' => 1,
                'step_code' => 'start',
                'is_start' => true,
                'config' => [
                    'class' => FakeWorkflowHandler::class,
                    'method' => 'handle',
                    'payload' => ['foo' => 'bar'],
                ],
            ],
            [
                'id' => 2,
                'step_code' => 'next',
                'is_start' => false,
                'config' => [
                    'class' => FakeWorkflowHandler::class,
                    'method' => 'handle',
                    'payload' => ['foo' => 'baz'],
                ],
            ],
        ], [
            ['from_step_id' => 1, 'to_step_id' => 2],
        ]);

        $target = $this->makeTarget(99);
        FakeWorkflowHandler::$calls = [];
        app()->instance(FakeWorkflowHandler::class, new FakeWorkflowHandler());

        $this->assertArrayHasKey('class', $workflow->steps->first()->step_config);

        (new WorkflowExecutor($workflow, $target))->runGraph($workflow->steps, $workflow->connections);

        $this->assertGreaterThanOrEqual(1, $this->logger->countLevel('info'));
        $this->assertTrue(
            $this->logger->containsData(fn($data) => ($data['handler'] ?? '') === FakeWorkflowHandler::class . '@handle')
        );
        $errorLogs = array_values(array_filter($this->logger->logs, fn($log) => $log['level'] === 'error'));
        if (!empty($errorLogs)) {
            $this->fail('Unexpected error logs: ' . json_encode($errorLogs));
        }
    }

    public function test_run_logs_debug_when_no_steps(): void
    {
        $workflow = new WorkflowProject(['id' => 123]);
        $workflow->setRelation('steps', collect());
        $workflow->setRelation('connections', collect());

        $target = $this->makeTarget(1);

        (new WorkflowExecutor($workflow, $target))->runGraph($workflow->steps, $workflow->connections);

        $this->assertTrue($this->logger->hasLevel('debug'));
    }

    public function test_step_missing_handler_logs_warning(): void
    {
        $workflow = $this->makeWorkflowWithSteps([
            [
                'id' => 10,
                'step_code' => 'no-handler',
                'is_start' => true,
                'step_type' => 'custom',
                'config' => [
                    // Missing class/method should trigger warning
                ],
            ],
        ]);

        $target = $this->makeTarget(42);

        (new WorkflowExecutor($workflow, $target))->runGraph($workflow->steps, $workflow->connections);

        $this->assertTrue($this->logger->hasLevel('warning'));
    }

    public function test_decision_step_branches_based_on_condition(): void
    {
        $workflow = $this->makeWorkflowWithSteps(
            [
                [
                    'id' => 1,
                    'step_code' => 'start',
                    'is_start' => true,
                    'step_type' => 'decision',
                    'config' => [
                        'condition' => '$order.payment_status == "paid"',
                        'yes_output' => 'target-a',
                        'no_output' => 'target-b',
                    ],
                ],
                [
                    'id' => 2,
                    'step_code' => 'yes-branch',
                    'step_type' => 'custom',
                    'config' => [
                        'class' => FakeWorkflowHandler::class,
                        'method' => 'handle',
                        'payload' => ['branch' => 'yes'],
                    ],
                ],
                [
                    'id' => 3,
                    'step_code' => 'no-branch',
                    'step_type' => 'custom',
                    'config' => [
                        'class' => FakeWorkflowHandler::class,
                        'method' => 'handle',
                        'payload' => ['branch' => 'no'],
                    ],
                ],
            ],
            [
                [
                    'from_step_id' => 1,
                    'to_step_id' => 2,
                    'condition' => ['source_handle' => 'target-a'],
                ],
                [
                    'from_step_id' => 1,
                    'to_step_id' => 3,
                    'condition' => ['source_handle' => 'target-b'],
                ],
            ]
        );

        $target = $this->makeTarget(50);
        $target->setAttribute('payment_status', 'paid');

        FakeWorkflowHandler::$calls = [];
        app()->instance(FakeWorkflowHandler::class, new FakeWorkflowHandler());

        $executor = new WorkflowExecutor($workflow, $target);

        // Sanity check: decision evaluation picks the "yes" handle
        $edgesForDecision = $workflow->connections
            ->filter(fn($conn) => $conn->from_step_id === 1)
            ->map(fn($conn) => [
                'to' => $conn->to_step_id,
                'handle' => $conn->condition['source_handle'] ?? null,
            ])
            ->values()
            ->all();

        $decisionStep = $workflow->steps->firstWhere('step_code', 'start');
        $ref = new \ReflectionClass($executor);
        $decisionMethod = $ref->getMethod('evaluateDecisionStep');
        $decisionMethod->setAccessible(true);
        $nextIds = $decisionMethod->invoke($executor, $decisionStep, $edgesForDecision);

        $this->assertSame([2], $nextIds, 'Decision step should point to yes branch for paid order');

        $executor->runGraph($workflow->steps, $workflow->connections);

        $this->assertTrue(
            collect(FakeWorkflowHandler::$calls)->contains(
                fn($call) => ($call['data']['branch'] ?? null) === 'yes'
            ),
            'Yes branch should be executed when condition is true'
        );

        $this->assertFalse(
            collect(FakeWorkflowHandler::$calls)->contains(
                fn($call) => ($call['data']['branch'] ?? null) === 'no'
            ),
            'No branch should be skipped when condition is true'
        );
    }

    public function test_decision_step_supports_today_variable(): void
    {
        $workflow = $this->makeWorkflowWithSteps(
            [
                [
                    'id' => 10,
                    'step_code' => 'start',
                    'is_start' => true,
                    'step_type' => 'decision',
                    'config' => [
                        'condition' => '$order.birthday == $today',
                        'yes_output' => 'target-a',
                        'no_output' => 'target-b',
                    ],
                ],
                [
                    'id' => 11,
                    'step_code' => 'yes-branch',
                    'step_type' => 'custom',
                    'config' => [
                        'class' => FakeWorkflowHandler::class,
                        'method' => 'handle',
                        'payload' => ['branch' => 'yes'],
                    ],
                ],
                [
                    'id' => 12,
                    'step_code' => 'no-branch',
                    'step_type' => 'custom',
                    'config' => [
                        'class' => FakeWorkflowHandler::class,
                        'method' => 'handle',
                        'payload' => ['branch' => 'no'],
                    ],
                ],
            ],
            [
                [
                    'from_step_id' => 10,
                    'to_step_id' => 11,
                    'condition' => ['source_handle' => 'target-a'],
                ],
                [
                    'from_step_id' => 10,
                    'to_step_id' => 12,
                    'condition' => ['source_handle' => 'target-b'],
                ],
            ]
        );

        $target = $this->makeTarget(501);
        $target->setAttribute('birthday', now()->toDateString());

        FakeWorkflowHandler::$calls = [];
        app()->instance(FakeWorkflowHandler::class, new FakeWorkflowHandler());

        (new WorkflowExecutor($workflow, $target))->runGraph($workflow->steps, $workflow->connections);

        $this->assertTrue(
            collect(FakeWorkflowHandler::$calls)->contains(
                fn($call) => ($call['data']['branch'] ?? null) === 'yes'
            ),
            'Yes branch should be executed when using $today helper variable'
        );
    }

    protected function makeWorkflowWithSteps(array $steps, array $connections = []): WorkflowProject
    {
        $workflow = new WorkflowProject(['id' => 1]);

        $stepModels = collect($steps)->map(function ($step) use ($workflow) {
            $model = new WorkflowStep([
                'project_id' => $workflow->id,
                'step_code' => $step['step_code'],
                'name' => $step['step_code'],
                'is_start' => $step['is_start'] ?? false,
                'step_type' => $step['step_type'] ?? null,
                'step_config' => $step['config'] ?? [],
            ]);
            $model->setAttribute('id', $step['id']);

            return $model;
        });

        $connectionModels = collect($connections)->map(function ($connection) use ($workflow) {
            return new WorkflowConnection([
                'project_id' => $workflow->id,
                'from_step_id' => $connection['from_step_id'],
                'to_step_id' => $connection['to_step_id'],
                'condition' => $connection['condition'] ?? null,
            ]);
        });

        $workflow->setRelation('steps', $stepModels);
        $workflow->setRelation('connections', $connectionModels);

        return $workflow;
    }

    protected function makeTarget(int $id): Model
    {
        $model = new class extends Model {
            protected $table = 'dummy';
            protected $guarded = [];
        };

        $model->setAttribute($model->getKeyName(), $id);
        $model->exists = true;

        return $model;
    }
}

class FakeWorkflowHandler
{
    public static array $calls = [];

    public static function handle(array $data, Model $target): void
    {
        self::$calls[] = compact('data', 'target');
    }
}

class DummyLogger
{
    public array $logs = [];

    public function info($data): void
    {
        $this->logs[] = ['level' => 'info', 'data' => $data];
    }

    public function warning($data): void
    {
        $this->logs[] = ['level' => 'warning', 'data' => $data];
    }

    public function error($data): void
    {
        $this->logs[] = ['level' => 'error', 'data' => $data];
    }

    public function debug($data): void
    {
        $this->logs[] = ['level' => 'debug', 'data' => $data];
    }

    public function hasLevel(string $level): bool
    {
        return collect($this->logs)->contains(fn($log) => $log['level'] === $level);
    }

    public function countLevel(string $level): int
    {
        return collect($this->logs)->filter(fn($log) => $log['level'] === $level)->count();
    }

    public function containsData(callable $callback): bool
    {
        return collect($this->logs)->contains(function ($log) use ($callback) {
            return $callback($log['data'] ?? []);
        });
    }
}
