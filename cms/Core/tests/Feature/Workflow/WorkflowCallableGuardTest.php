<?php

declare(strict_types=1);

use Cms\Core\Workflows\Runtime\Runners\BlueprintCallableRunner;
use Cms\Core\Workflows\Runtime\Runners\ChangeRunner;
use Cms\Core\Workflows\Runtime\Runners\CloneObjectRunner;
use Cms\Core\Workflows\Runtime\Runners\CommitObjectsRunner;
use Cms\Core\Workflows\Runtime\Runners\CreateObjectRunner;
use Cms\Core\Workflows\Runtime\Runners\DeleteObjectRunner;
use Cms\Core\Workflows\Runtime\Runners\RetrieveRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    config(['core.workflow_security' => [
        'services' => [], 'jobs' => [], 'events' => [], 'commands' => [], 'models' => 'registry',
    ]]);

    Schema::dropIfExists('guard_widgets');
    Schema::create('guard_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    GuardTestService::$calls = 0;
});

function guardStep(string $type, array $config): StepDefinition
{
    return new StepDefinition(null, null, $type, 's1', null, 's1', $config);
}

function guardRoot(): Model
{
    $model = new class extends Model {
        protected $table = 'guard_widgets';
        protected $guarded = [];
    };
    $model->setAttribute('id', 1);
    $model->exists = true;

    return $model;
}

it('refuses a service class that is not allowlisted', function (): void {
    $result = (new BlueprintCallableRunner())->run(guardStep('blueprint_callable', [
        'callableType' => 'service',
        'class' => GuardTestService::class,
        'method' => 'run',
    ]), new WorkflowContext(guardRoot()));

    expect($result->status)->toBe('failed')
        ->and($result->error["message"])->toBe('blueprint_callable: class not allowed')
        ->and(GuardTestService::$calls)->toBe(0);
});

it('refuses a method the service entry does not list', function (): void {
    config(['core.workflow_security.services' => [GuardTestService::class => ['other']]]);

    $result = (new BlueprintCallableRunner())->run(guardStep('blueprint_callable', [
        'callableType' => 'service',
        'class' => GuardTestService::class,
        'method' => 'run',
    ]), new WorkflowContext(guardRoot()));

    expect($result->status)->toBe('failed')
        ->and($result->error["message"])->toContain('not allowed')
        ->and(GuardTestService::$calls)->toBe(0);
});

it('refuses an artisan command that is not allowlisted', function (): void {
    Artisan::spy();

    $result = (new BlueprintCallableRunner())->run(guardStep('blueprint_callable', [
        'callableType' => 'command',
        'class' => \Illuminate\Database\Console\WipeCommand::class,
    ]), new WorkflowContext(guardRoot()));

    expect($result->status)->toBe('failed')
        ->and($result->error["message"])->toContain('not allowed');
    Artisan::shouldNotHaveReceived('call');
});

it('refuses jobs and events that are not allowlisted', function (string $type, string $class): void {
    Event::fake([\Illuminate\Auth\Events\Logout::class]);
    \Illuminate\Support\Facades\Bus::fake();

    $result = (new BlueprintCallableRunner())->run(guardStep('blueprint_callable', [
        'callableType' => $type,
        'class' => $class,
    ]), new WorkflowContext(guardRoot()));

    expect($result->status)->toBe('failed')->and($result->error["message"])->toContain('not allowed');
    Event::assertNotDispatched(\Illuminate\Auth\Events\Logout::class);
    \Illuminate\Support\Facades\Bus::assertNothingDispatched();
})->with([
    'job' => ['job', \Illuminate\Queue\CallQueuedClosure::class],
    'event' => ['event', \Illuminate\Auth\Events\Logout::class],
]);

it('runs an allowlisted service method', function (): void {
    config(['core.workflow_security.services' => [GuardTestService::class => ['run']]]);

    $context = new WorkflowContext(guardRoot());
    $result = (new BlueprintCallableRunner())->run(guardStep('blueprint_callable', [
        'callableType' => 'service',
        'class' => GuardTestService::class,
        'method' => 'run',
        'storeAs' => 'vars.out',
    ]), $context);

    expect($result->status)->toBe('success')
        ->and(GuardTestService::$calls)->toBe(1)
        ->and($context->getVarPath('out'))->toBe('ran');
});

it('allows a service through a namespace prefix and a command by its artisan name', function (): void {
    config(['core.workflow_security.services' => ['GuardTestNs\\' => ['*']]]);
    config(['core.workflow_security.commands' => ['db:wipe']]);

    $guard = new WorkflowCallableGuard();

    expect($guard->allowsCallable('service', 'GuardTestNs\\Anything', 'go'))->toBeTrue()
        ->and($guard->allowsCallable('service', 'GuardTestNs\\Anything', '__construct'))->toBeFalse()
        ->and($guard->allowsCallable('service', 'GuardTestNsOther\\X', 'go'))->toBeFalse()
        ->and($guard->allowsCallable('command', \Illuminate\Database\Console\WipeCommand::class, null))->toBeTrue()
        ->and($guard->allowsCommand('db:wipe'))->toBeTrue()
        ->and($guard->allowsCommand('migrate:fresh'))->toBeFalse()
        ->and($guard->allowsCallable('script', GuardTestService::class, 'run'))->toBeFalse();
});

it('refuses retrieve/create_object/change/delete on a model outside the registry', function (): void {
    $vendorModel = DatabaseNotification::class;
    $notification = DatabaseNotification::query()->forceCreate([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'x',
        'notifiable_type' => 'u',
        'notifiable_id' => 1,
        'data' => '{}',
    ]);

    $context = new WorkflowContext(guardRoot());
    $context->setVarPath('target', $notification);
    $context->setVarPath('snapshot', ['__class' => $vendorModel, '__id' => $notification->id]);

    $results = [
        'retrieve' => (new RetrieveRunner())->run(guardStep('retrieve', ['model' => $vendorModel, 'storeAs' => 'vars.found']), $context),
        'create_object' => (new CreateObjectRunner())->run(guardStep('create_object', [
            'model' => $vendorModel, 'fields' => [['field' => 'type', 'valueExpr' => "'y'"]],
        ]), $context),
        'change' => (new ChangeRunner())->run(guardStep('change', [
            'targetExpr' => 'vars.target', 'changes' => [['field' => 'type', 'valueExpr' => "'z'"]],
        ]), $context),
        'clone_object' => (new CloneObjectRunner())->run(guardStep('clone_object', [
            'sourceExpr' => 'vars.target', 'save' => true, 'storeAs' => 'vars.copy',
        ]), $context),
        'commit_objects' => (new CommitObjectsRunner())->run(guardStep('commit_objects', ['targetExpr' => 'vars.target']), $context),
        'delete_object' => (new DeleteObjectRunner())->run(guardStep('delete_object', ['targetExpr' => 'vars.target']), $context),
    ];

    foreach ($results as $type => $result) {
        expect($result->status)->toBe('failed', $type)
            ->and($result->error["message"])->toBe($type . ': model not allowed');
    }

    // A hand-written {__class, __id} array is not a reference the run produced (review 2026-11-24 finding 24).
    $unsigned = (new ChangeRunner())->run(guardStep('change', [
        'targetExpr' => 'vars.snapshot', 'changes' => [['field' => 'type', 'valueExpr' => "'z'"]],
    ]), $context);
    expect($unsigned->status)->toBe('failed');

    expect(DatabaseNotification::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->first()->type)->toBe('x')
        ->and($context->getVarPath('found'))->toBeNull();
});

it('lets the model steps use a model from the registry', function (): void {
    \Cms\Core\Tests\Support\WorkflowTestDelegate::allow(GuardWidget::class);
    $context = new WorkflowContext(guardRoot());

    $create = (new CreateObjectRunner())->run(guardStep('create_object', [
        'model' => GuardWidget::class, 'fields' => [['field' => 'name', 'valueExpr' => "'w'"]], 'storeAs' => 'vars.w',
    ]), $context);
    $retrieve = (new RetrieveRunner())->run(guardStep('retrieve', ['model' => GuardWidget::class, 'storeAs' => 'vars.all']), $context);

    expect($create->status)->toBe('success')
        ->and($retrieve->status)->toBe('success')
        ->and($retrieve->resultData['count'])->toBe(1);
});

it('honours an explicit model list', function (): void {
    config(['core.workflow_security.models' => [GuardWidget::class]]);
    $guard = new WorkflowCallableGuard();

    expect($guard->allowsModel(GuardWidget::class))->toBeTrue()
        ->and($guard->allowsModel(\App\Models\User::class))->toBeFalse()
        ->and($guard->allowsModel(DatabaseNotification::class))->toBeFalse();
});

class GuardTestService
{
    public static int $calls = 0;

    public function run(): string
    {
        ++self::$calls;

        return 'ran';
    }
}

class GuardWidget extends Model
{
    protected $table = 'guard_widgets';
    protected $guarded = [];
}
