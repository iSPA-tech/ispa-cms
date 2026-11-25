<?php

declare(strict_types=1);

use Cms\Core\Workflows\Runtime\Runners\BlueprintCallableRunner;
use Cms\Core\Workflows\Runtime\Runners\ChangeRunner;
use Cms\Core\Workflows\Runtime\Runners\CreateObjectRunner;
use Cms\Core\Workflows\Runtime\Runners\RetrieveRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Workflow model steps never touch sensitive models, never copy hidden attributes into results or context, and never
 * write attributes the model does not allow for mass assignment.
 */

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    config(['core.workflow_security' => [
        'services' => [], 'jobs' => [], 'events' => [], 'commands' => [], 'models' => 'registry', 'deny_models' => [],
    ]]);

    Schema::dropIfExists('sensitive_widgets');
    Schema::create('sensitive_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('secret')->nullable();
        $table->string('role')->nullable();
        $table->timestamps();
    });

    SensitiveWidgetService::$model = null;
    // Runs act for a delegate allowed every ability on the test model (review 2026-11-24 finding 3).
    \Cms\Core\Tests\Support\WorkflowTestDelegate::allow(SensitiveWidget::class);
});

function sensitiveStep(string $type, array $config): StepDefinition
{
    return new StepDefinition(null, null, $type, 's1', null, 's1', $config);
}

function sensitiveContext(): WorkflowContext
{
    $root = new SensitiveWidget();
    $root->setAttribute('id', 1);
    $root->exists = true;

    return new WorkflowContext($root);
}

it('denies the Core sensitive models even when the registry would allow them', function (string $class): void {
    expect(app(WorkflowCallableGuard::class)->allowsModel($class))->toBeFalse();
})->with([
    'user' => \App\Models\User::class,
    'user lowercase' => 'app\\models\\user',
    'user leading slash' => '\\App\\Models\\User',
    'password reset' => \App\Models\PasswordReset::class,
    'role' => \Cms\Core\Models\Role::class,
    'permission' => \Cms\Core\Models\Permission::class,
    'api token' => \Cms\Core\Models\ApiAccessToken::class,
    'auth challenge' => \Cms\Core\Models\AuthChallenge::class,
    'mfa factor' => \Cms\Core\Models\UserMfaFactor::class,
    'mfa recovery code' => \Cms\Core\Models\UserMfaRecoveryCode::class,
    'setting' => \Cms\Core\Models\Setting::class,
    'user setting' => \Cms\Core\Models\UserSetting::class,
    'module install' => \Cms\Core\Models\ModuleInstall::class,
    'config package run' => \Cms\Core\Models\ConfigPackageRun::class,
    'audit log' => \Cms\Core\Models\AuditLog::class,
    'webhook receipt' => \Cms\Core\Models\WebhookReceipt::class,
    'workflow project' => \Cms\Core\Models\Workflows\WorkflowProject::class,
    'workflow step' => \Cms\Core\Models\Workflows\WorkflowStep::class,
    'workflow instance case' => 'CMS\\CORE\\MODELS\\WORKFLOWS\\WorkflowInstance',
    'agent approval' => \Cms\Core\Models\Agents\AgentApproval::class,
    'agent policy' => \Cms\Core\Models\Agents\AgentPolicy::class,
    'agent schedule' => \Cms\Core\Models\Agents\AgentSchedule::class,
    'agent tool binding' => \Cms\Core\Models\Agents\AgentToolBinding::class,
    'backup' => \Cms\Core\Models\Backup\Backup::class,
    'data scope grant' => \Cms\Core\Models\DataScope\DataScopeGrant::class,
]);

it('keeps the Core deny list when config allows the model explicitly or clears deny_models', function (): void {
    config(['core.workflow_security.models' => [\App\Models\User::class, 'Cms\\Core\\Models\\']]);
    config(['core.workflow_security.deny_models' => []]);

    $guard = app(WorkflowCallableGuard::class);

    expect($guard->allowsModel(\App\Models\User::class))->toBeFalse()
        ->and($guard->allowsModel(\Cms\Core\Models\Role::class))->toBeFalse()
        ->and($guard->allowsModel(\Cms\Core\Models\Tag::class))->toBeTrue();
});

it('lets a module extend the deny list by class or namespace prefix', function (): void {
    $guard = app(WorkflowCallableGuard::class);
    expect($guard->allowsModel(SensitiveWidget::class))->toBeTrue();

    config(['core.workflow_security.deny_models' => [strtolower(SensitiveWidget::class)]]);
    expect($guard->allowsModel(SensitiveWidget::class))->toBeFalse();

    config(['core.workflow_security.deny_models' => ['Cms\\Core\\Models\\']]);
    expect($guard->allowsModel(\Cms\Core\Models\Tag::class))->toBeFalse();
});

it('refuses retrieve on the user model before any query', function (): void {
    $context = sensitiveContext();
    $result = (new RetrieveRunner())->run(sensitiveStep('retrieve', [
        'model' => \App\Models\User::class, 'storeAs' => 'vars.users',
    ]), $context);

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toBe('retrieve: model not allowed')
        ->and($context->getVarPath('users'))->toBeNull();
});

it('retrieve keeps hidden attributes out of the stored snapshot and the step result', function (): void {
    SensitiveWidget::query()->forceCreate(['name' => 'w', 'secret' => 'hash-value']);
    $context = sensitiveContext();

    $first = (new RetrieveRunner())->run(sensitiveStep('retrieve', [
        'model' => SensitiveWidget::class, 'storeAs' => 'vars.one', 'first' => true,
    ]), $context);
    $list = (new RetrieveRunner())->run(sensitiveStep('retrieve', [
        'model' => SensitiveWidget::class, 'storeAs' => 'vars.all',
    ]), $context);

    expect($first->status)->toBe('success')
        ->and($list->status)->toBe('success')
        ->and(json_encode($first->resultData))->not->toContain('hash-value')
        ->and(json_encode($list->resultData))->not->toContain('hash-value')
        ->and(json_encode($context->getVarPath('one')))->not->toContain('hash-value')
        ->and(json_encode($context->getVarPath('all')))->not->toContain('hash-value')
        ->and($context->getVarPath('one')['attributes']['name'])->toBe('w');
});

it('blueprint_callable keeps hidden attributes out of a returned model snapshot', function (): void {
    config(['core.workflow_security.services' => [SensitiveWidgetService::class => ['find']]]);
    SensitiveWidget::query()->forceCreate(['name' => 'w', 'secret' => 'hash-value']);
    $context = sensitiveContext();

    $result = (new BlueprintCallableRunner())->run(sensitiveStep('blueprint_callable', [
        'callableType' => 'service',
        'class' => SensitiveWidgetService::class,
        'method' => 'find',
        'storeAs' => 'vars.found',
    ]), $context);

    expect($result->status)->toBe('success')
        ->and(json_encode($result->resultData))->not->toContain('hash-value');
});

it('create_object refuses a field the model does not allow for mass assignment', function (): void {
    $result = (new CreateObjectRunner())->run(sensitiveStep('create_object', [
        'model' => SensitiveWidget::class,
        'fields' => [
            ['field' => 'name', 'valueExpr' => "'ok'"],
            ['field' => 'role', 'valueExpr' => "'admin'"],
        ],
    ]), sensitiveContext());

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toBe('create_object: field not fillable: role')
        ->and(SensitiveWidget::query()->count())->toBe(0);
});

it('change refuses a field the model does not allow for mass assignment and saves nothing', function (): void {
    $widget = SensitiveWidget::query()->forceCreate(['name' => 'w', 'role' => 'viewer']);
    $context = sensitiveContext();
    $context->setVarPath('target', $widget);

    $result = (new ChangeRunner())->run(sensitiveStep('change', [
        'targetExpr' => 'vars.target',
        'changes' => [
            ['field' => 'name', 'valueExpr' => "'renamed'"],
            ['field' => 'role', 'valueExpr' => "'admin'"],
        ],
    ]), $context);

    $fresh = $widget->fresh();
    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toBe('change: field not fillable: role')
        ->and($fresh->role)->toBe('viewer')
        ->and($fresh->name)->toBe('w');
});

it('change and create_object still write fillable fields', function (): void {
    $create = (new CreateObjectRunner())->run(sensitiveStep('create_object', [
        'model' => SensitiveWidget::class,
        'fields' => [['field' => 'name', 'valueExpr' => "'made'"]],
    ]), sensitiveContext());

    $widget = SensitiveWidget::query()->firstOrFail();
    $context = sensitiveContext();
    $context->setVarPath('target', $widget);
    $change = (new ChangeRunner())->run(sensitiveStep('change', [
        'targetExpr' => 'vars.target',
        'changes' => [['field' => 'name', 'valueExpr' => "'changed'"]],
    ]), $context);

    expect($create->status)->toBe('success')
        ->and($change->status)->toBe('success')
        ->and($widget->fresh()->name)->toBe('changed');
});

class SensitiveWidget extends Model
{
    protected $table = 'sensitive_widgets';

    protected $fillable = ['name'];

    protected $hidden = ['secret'];
}

class SensitiveWidgetService
{
    public static ?Model $model = null;

    public function find(): ?SensitiveWidget
    {
        return SensitiveWidget::query()->first();
    }
}
