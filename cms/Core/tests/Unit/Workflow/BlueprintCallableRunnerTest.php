<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\BlueprintCallableRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Console\Command;
use Cms\Core\Tests\TestCase;

class BlueprintCallableRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('blueprint_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new DummyWorkflowCommand());
    }

    public function test_service_callable_executes_and_stores(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new BlueprintCallableRunner();
        $step = new StepDefinition(null, null, 'blueprint_callable', 'svc', null, 'svc', [
            'callableType' => 'service',
            'class' => DummyWorkflowService::class,
            'method' => 'doWork',
            'argsMapping' => [
                'model' => 'root',
                'suffix' => "'-ok'",
            ],
            'storeAs' => 'vars.result',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $stored = $ctx->getVarPath('result');
        $this->assertSame('root-ok', $stored);
    }

    public function test_job_dispatch_sync(): void
    {
        Bus::fake();
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new BlueprintCallableRunner();
        $step = new StepDefinition(null, null, 'blueprint_callable', 'job', null, 'job', [
            'callableType' => 'job',
            'class' => DummyWorkflowJob::class,
            'argsMapping' => [
                'model' => 'root',
            ],
            'execution' => [
                'mode' => 'sync',
            ],
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame('sync', $res->resultData['mode']);
    }

    public function test_command_execution_returns_exit_code(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new BlueprintCallableRunner();
        $step = new StepDefinition(null, null, 'blueprint_callable', 'cmd', null, 'cmd', [
            'callableType' => 'command',
            'class' => DummyWorkflowCommand::class,
            'argsMapping' => [
                '--foo' => "'bar'",
            ],
        ]);

        $res = $runner->run($step, $ctx);
        $this->assertSame('success', $res->status);
        $this->assertEquals(0, $res->resultData['exit_code']);
    }

    public function test_missing_required_arg_fails(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new BlueprintCallableRunner();
        $step = new StepDefinition(null, null, 'blueprint_callable', 'svc', null, 'svc', [
            'callableType' => 'service',
            'class' => DummyRequiredArgService::class,
            'method' => 'needArg',
        ]);

        $res = $runner->run($step, $ctx);
        $this->assertSame('failed', $res->status);
    }

    protected function makeRoot(): Model
    {
        $model = new class extends Model {
            protected $table = 'blueprint_targets';
            protected $guarded = [];
        };
        $model->setAttribute('id', 1);
        $model->exists = true;
        return $model;
    }
}

class DummyWorkflowService
{
    public function doWork(Model $model, string $suffix = ''): string
    {
        return 'root' . $suffix;
    }
}

class DummyWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Model $model)
    {
    }

    public function handle(): string
    {
        return 'handled';
    }
}

class DummyWorkflowCommand extends Command
{
    protected $signature = 'dummy:workflow {--foo=}';

    public function __construct()
    {
        parent::__construct();
        $this->setName('dummy:workflow');
    }

    public function handle(): int
    {
        $this->line('ok');
        return 0;
    }
}

class DummyRequiredArgService
{
    public function needArg(string $required): string
    {
        return $required;
    }
}
