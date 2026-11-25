<?php

declare(strict_types=1);

use Cms\Core\Workflows\Runtime\Runners\BlueprintCallableRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
 * Commands: only registered console commands, only listed options. Async jobs: built with their mapped arguments,
 * only on allowlisted queues.
 */

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    config(['core.workflow_security' => [
        'services' => [], 'jobs' => [], 'events' => [], 'commands' => [], 'models' => 'registry',
        'deny_models' => [], 'job_queues' => [], 'job_connections' => [],
    ]]);
});

function hardeningStep(array $config): StepDefinition
{
    return new StepDefinition(null, null, 'blueprint_callable', 's1', null, 's1', $config);
}

function hardeningContext(): WorkflowContext
{
    $root = new class extends Model {
        protected $guarded = [];
    };
    $root->setAttribute('id', 1);
    $root->exists = true;

    return new WorkflowContext($root);
}

it('matches a command class only against registered console commands', function (): void {
    config(['core.workflow_security.commands' => ['db:wipe']]);
    $guard = app(WorkflowCallableGuard::class);

    expect($guard->allowsCommand(\Illuminate\Database\Console\WipeCommand::class))->toBeTrue()
        ->and($guard->allowsCommand('db:wipe'))->toBeTrue()
        // A class that is not a registered console command, even one whose name looks allowed.
        ->and($guard->allowsCommand(HardeningFakeCommand::class))->toBeFalse()
        ->and($guard->allowsCommand('Some\\Unknown\\NotLoaded'))->toBeFalse();
});

it('refuses a command option that the allowlist does not name', function (): void {
    config(['core.workflow_security.commands' => ['db:wipe']]);
    Artisan::spy();

    $result = (new BlueprintCallableRunner())->run(hardeningStep([
        'callableType' => 'command',
        'class' => 'db:wipe',
        'argsMapping' => ['--database' => "'other'"],
    ]), hardeningContext());

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toBe('blueprint_callable: command option not allowed: --database');
    Artisan::shouldNotHaveReceived('call');
});

it('passes a listed command option', function (): void {
    config(['core.workflow_security.commands' => ['about' => ['--only']]]);

    $result = (new BlueprintCallableRunner())->run(hardeningStep([
        'callableType' => 'command',
        'class' => 'about',
        'argsMapping' => ['--only' => "'environment'"],
    ]), hardeningContext());

    expect($result->status)->toBe('success')
        ->and($result->resultData['exit_code'])->toBe(0);
});

it('dispatches an async job with its mapped constructor arguments', function (): void {
    Queue::fake();
    config(['core.workflow_security.jobs' => [HardeningJob::class]]);

    $result = (new BlueprintCallableRunner())->run(hardeningStep([
        'callableType' => 'job',
        'class' => HardeningJob::class,
        'argsMapping' => ['label' => "'from-workflow'"],
        'execution' => ['mode' => 'async'],
    ]), hardeningContext());

    expect($result->status)->toBe('success');
    Queue::assertPushed(HardeningJob::class, fn (HardeningJob $job) => $job->label === 'from-workflow' && $job->count === 3);
});

it('refuses an async job queue or connection that is not allowlisted', function (array $execution): void {
    Queue::fake();
    config(['core.workflow_security.jobs' => [HardeningJob::class]]);

    $result = (new BlueprintCallableRunner())->run(hardeningStep([
        'callableType' => 'job',
        'class' => HardeningJob::class,
        'argsMapping' => ['label' => "'x'"],
        'execution' => ['mode' => 'async'] + $execution,
    ]), hardeningContext());

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toBe('blueprint_callable: queue not allowed');
    Queue::assertNothingPushed();
})->with([
    'queue' => [['queue' => 'high']],
    'connection' => [['connection' => 'sync']],
]);

it('uses an allowlisted queue', function (): void {
    Queue::fake();
    config(['core.workflow_security.jobs' => [HardeningJob::class], 'core.workflow_security.job_queues' => ['workflows']]);

    $result = (new BlueprintCallableRunner())->run(hardeningStep([
        'callableType' => 'job',
        'class' => HardeningJob::class,
        'argsMapping' => ['label' => "'x'"],
        'execution' => ['mode' => 'async', 'queue' => 'workflows'],
    ]), hardeningContext());

    expect($result->status)->toBe('success');
    Queue::assertPushedOn('workflows', HardeningJob::class);
});

class HardeningJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $label, public int $count = 3)
    {
    }

    public function handle(): void
    {
    }
}

class HardeningFakeCommand
{
    protected $signature = 'db:wipe';
}
