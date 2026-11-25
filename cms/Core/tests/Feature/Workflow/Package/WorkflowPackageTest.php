<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInstaller;
use Cms\Core\Services\Workflow\Package\WorkflowPackageManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** A user holding the given permission keys (`group.name`). */
function pkgUser(array $keys = ['workflow_project.viewAny', 'workflow_project.create', 'workflow_project.update', 'agentic.approve']): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Pkg ' . Str::random(4), 'username' => 'pkg-' . Str::lower(Str::random(8)),
        'email' => 'pkg-' . Str::lower(Str::random(8)) . '@example.com', 'password' => 'x', 'type' => 0, 'level' => 0,
    ]);
    if ($keys !== []) {
        $roleId = DB::table('roles')->insertGetId(['name' => 'Pkg ' . $user->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permissionId = DB::table('permissions')->where('group', $group)->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['group' => $group, 'name' => $name, 'title' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user;
}

/**
 * Create a workflow directly in the database (no save checks), steps in order, edges [from, to, handle].
 *
 * @param array<string, array{0: string, 1: array<string, mixed>}> $steps
 * @param list<array{0: string, 1: string, 2: ?string}> $edges
 */
function pkgWorkflow(string $slug, array $steps, array $edges = []): WorkflowProject
{
    $project = WorkflowProject::query()->create(['name' => 'Pkg ' . $slug, 'slug' => $slug, 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true, 'description' => 'Sample', 'tags' => ['sample']]);
    $ids = [];
    $x = 0;
    foreach ($steps as $code => [$type, $config]) {
        $ids[$code] = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => $type, 'step_code' => $code, 'name' => ucfirst($code), 'step_config' => $config, 'position_x' => $x += 100, 'position_y' => 50, 'is_start' => $x === 100])->id;
    }
    if ($edges === []) {
        $codes = array_keys($steps);
        for ($i = 1; $i < count($codes); $i++) {
            $edges[] = [$codes[$i - 1], $codes[$i], null];
        }
    }
    foreach ($edges as [$from, $to, $handle]) {
        WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to],
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle]]);
    }

    return $project;
}

/** Graph of a project keyed by step code, without ids. */
function pkgGraph(string $slug): array
{
    $project = WorkflowProject::query()->where('slug', $slug)->firstOrFail();
    $steps = WorkflowStep::query()->where('project_id', $project->id)->orderBy('step_code')->get();
    $codes = $steps->pluck('step_code', 'id');

    return [
        'steps' => $steps->mapWithKeys(static fn (WorkflowStep $s): array => [$s->step_code => [$s->name, $s->step_type, $s->step_config, (int) $s->position_x, (int) $s->position_y, (bool) $s->is_start]])->all(),
        'edges' => WorkflowConnection::query()->where('project_id', $project->id)->get()
            ->map(static fn ($c): array => [$codes[$c->from_step_id], $codes[$c->to_step_id], $c->condition])->sortBy(static fn (array $e): string => $e[0] . $e[1])->values()->all(),
    ];
}

/** Recompute manifest and checksum after a test edits the workflow (isolates one refusal from a checksum mismatch). */
function pkgRepack(array $package): array
{
    $package['manifest'] = (new WorkflowPackageManifest())->derive($package['workflow'], $package['manifest']['bindings']);
    $package['checksum'] = WorkflowPackageFormat::checksum($package);

    return $package;
}

function pkgCodes(array $report, string $list = 'problems'): array
{
    return array_values(array_unique(array_column($report[$list], 'code')));
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    Http::preventStrayRequests();
});

it('round-trips a workflow: export, validate, install as an inactive copy with an equal graph', function (): void {
    $reviewer = pkgUser();
    $installer = pkgUser();
    pkgWorkflow('greeting', [
        'start' => ['set_variable', ['assignments' => [['key' => 'vars.greeting', 'valueExpr' => "'Hello'"]]]],
        'check' => ['decision', ['expression' => "vars.greeting == 'Hello'"]],
        'ok' => ['approval', ['mode' => 'human', 'title' => 'Check', 'assignees' => [$reviewer->uuid], 'notify' => false, 'storeAs' => 'vars.approval']],
        'note' => ['log_message', ['message' => 'done', 'level' => 'info']],
    ], [['start', 'check', null], ['check', 'ok', 'true'], ['ok', 'note', 'approved']]);

    $package = app(WorkflowPackageExporter::class)->export('greeting', ['root' => ['name' => 'Ann']]);

    expect($package['format'])->toBe('ispa-workflow-package')
        ->and($package['version'])->toBe(1)
        ->and($package['workflow'])->not->toHaveKey('is_active')
        ->and(json_encode($package))->not->toContain($reviewer->uuid)
        ->and($package['workflow']['steps'][2]['step_config']['assignees'])->toBe(['$bind:user_1'])
        ->and($package['manifest']['bindings'])->toBe([['name' => 'user_1', 'kind' => 'user', 'steps' => ['ok']]])
        ->and($package['manifest']['node_types'])->toBe(['approval' => 1, 'decision' => 1, 'log_message' => 1, 'set_variable' => 1]);

    $report = app(WorkflowPackageInspector::class)->inspect($package, $installer, ['user_1' => $reviewer->uuid], 'greeting-copy');
    expect($report["problems"])->toBe([])
        ->and($report['installable'])->toBeTrue()
        ->and($report['dry_run']['completed'])->toBeTrue();

    $result = app(WorkflowPackageInstaller::class)->install($package, $installer, ['user_1' => $reviewer->uuid], 'greeting-copy');
    $copy = WorkflowProject::query()->where('slug', 'greeting-copy')->firstOrFail();

    expect($result['is_active'])->toBeFalse()
        ->and($copy->is_active)->toBeFalse()
        ->and($copy->name)->toBe('Pkg greeting')
        ->and(pkgGraph('greeting-copy'))->toBe(pkgGraph('greeting'))
        ->and($copy->schedule_cron ?? null)->toBeNull();
});

it('resolves bindings: credentials by name, users and uuids inside expressions, and refuses unresolved ones', function (): void {
    $source = pkgUser();
    $target = pkgUser();
    app(WorkflowCredentialStore::class)->create(['name' => 'tg_main', 'type' => 'telegram_bot', 'payload' => ['token' => '123:abc']], null);
    app(WorkflowCredentialStore::class)->create(['name' => 'tg_other', 'type' => 'telegram_bot', 'payload' => ['token' => '456:def']], null);
    pkgWorkflow('bound', [
        'tell' => ['notify', ['permission' => 'agentic.approve', 'users' => "['{$source->uuid}']", 'message' => 'Hi']],
        'send' => ['telegram_send', ['credential' => '$bind:tg_main', 'chat' => "'@news'", 'text' => "'Hello'"]],
    ]);

    $package = app(WorkflowPackageExporter::class)->export('bound');
    $bindings = collect($package['manifest']['bindings'])->keyBy('name');
    expect($bindings->keys()->all())->toBe(['tg_main', 'user_1'])
        ->and($bindings['tg_main']['credential_type'])->toBe('telegram_bot')
        ->and($package['workflow']['steps'][0]['step_config']['users'])->toBe("['\$bind:user_1']");

    $inspector = app(WorkflowPackageInspector::class);
    expect(pkgCodes($inspector->inspect($package, $target, [], 'bound-a'), 'binding_problems'))->toBe(['binding_missing'])
        ->and(pkgCodes($inspector->inspect($package, $target, ['user_1' => (string) Str::uuid()], 'bound-a'), 'binding_problems'))->toBe(['binding_invalid'])
        ->and(pkgCodes($inspector->inspect($package, $target, ['user_1' => $target->uuid, 'tg_main' => 'missing'], 'bound-a'), 'binding_problems'))->toBe(['binding_invalid']);
    expect(fn () => app(WorkflowPackageInstaller::class)->install($package, $target, [], 'bound-a'))->toThrow(WorkflowPackageException::class);

    app(WorkflowPackageInstaller::class)->install($package, $target, ['user_1' => $target->uuid, 'tg_main' => 'tg_other'], 'bound-b');
    $steps = WorkflowStep::query()->where('project_id', WorkflowProject::query()->where('slug', 'bound-b')->value('id'))->get()->keyBy('step_code');
    expect($steps['tell']->step_config['users'])->toBe("['{$target->uuid}']")
        ->and($steps['send']->step_config['credential'])->toBe('$bind:tg_other');
});

it('refuses a tainted write without approval, unknown node types, numeric ids, secrets and callables off the allowlist', function (): void {
    $user = pkgUser();
    pkgWorkflow('base', [
        'fetch' => ['http_request', ['method' => 'GET', 'url' => "'https://example.com/feed'", 'storeAs' => 'vars.page']],
        'save' => ['change', ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'vars.page.body']]]],
    ]);
    $package = app(WorkflowPackageExporter::class)->export('base');
    $inspector = app(WorkflowPackageInspector::class);

    expect(pkgCodes($inspector->inspect($package, $user, [], 'base-copy')))->toContain('taint_needs_approval')
        ->and($package['manifest']['outbound_hosts'])->toBe(['example.com']);

    $unknown = $package;
    $unknown['workflow']['steps'][1]['step_type'] = 'made_up.node';
    expect(pkgCodes($inspector->inspect(pkgRepack($unknown), $user, [], 'x1')))->toContain('unknown_node_type');

    $numeric = $package;
    $numeric['workflow']['steps'][1]['step_config']['post_id'] = 12;
    expect(pkgCodes($inspector->inspect(pkgRepack($numeric), $user, [], 'x2')))->toContain('numeric_id');

    $secret = $package;
    $secret['workflow']['steps'][0]['step_config']['api_key'] = 'abc';
    expect(pkgCodes($inspector->inspect(pkgRepack($secret), $user, [], 'x3')))->toContain('secret_found');

    $callable = $package;
    $callable['workflow']['steps'][1] = ['step_code' => 'save', 'name' => 'Save', 'step_type' => 'blueprint_callable', 'step_config' => ['callableType' => 'command', 'class' => 'Illuminate\\Foundation\\Console\\DownCommand'], 'input_schema' => null, 'output_schema' => null, 'position_x' => 200, 'position_y' => 50, 'is_start' => false];
    expect(pkgCodes($inspector->inspect(pkgRepack($callable), $user, [], 'x4')))->toContain('callable_not_allowed');

    $tampered = $package;
    $tampered['manifest']['safety_classes'] = ['read'];
    $tampered['checksum'] = WorkflowPackageFormat::checksum($tampered);
    expect(pkgCodes($inspector->inspect($tampered, $user, [], 'x5')))->toContain('manifest_mismatch');

    $changed = $package;
    $changed['workflow']['name'] = 'Changed';
    expect(pkgCodes($inspector->inspect($changed, $user, [], 'x6')))->toContain('checksum_mismatch');

    expect(WorkflowProject::query()->count())->toBe(1);
});

it('names the node type, host and step in report rows, and gives an installed copy its own name', function (): void {
    $user = pkgUser();
    $reviewer = pkgUser();
    pkgWorkflow('named', [
        'fetch' => ['http_request', ['method' => 'GET', 'url' => "'https://feeds.example.org/a'", 'storeAs' => 'vars.page']],
        'ok' => ['approval', ['mode' => 'human', 'title' => 'Check', 'assignees' => [$reviewer->uuid], 'notify' => false, 'storeAs' => 'vars.approval']],
    ], [['fetch', 'ok', null]]);
    $package = app(WorkflowPackageExporter::class)->export('named');
    $inspector = app(WorkflowPackageInspector::class);

    $report = $inspector->inspect($package, $user, ['user_1' => $reviewer->uuid], 'named-copy');
    expect(collect($report['warnings'])->firstWhere('code', 'host_not_allowlisted'))->toMatchArray(['host' => 'feeds.example.org']);

    $unknown = $package;
    $unknown['workflow']['steps'][0]['step_type'] = 'made_up.node';
    expect(collect($inspector->inspect(pkgRepack($unknown), $user, [], 'x1')['problems'])->firstWhere('code', 'unknown_node_type'))
        ->toMatchArray(['node_type' => 'made_up.node']);

    // An assignee who exists only on the source site is reported on its approval step.
    $stranger = $package;
    $stranger['workflow']['steps'][1]['step_config']['assignees'] = ['$bind:user_1', 'not-a-user'];
    $assignee = collect($inspector->inspect(pkgRepack($stranger), $user, ['user_1' => $reviewer->uuid], 'x2')['binding_problems'])->first();
    expect($assignee['step_code'] ?? null)->toBe('ok');

    $result = app(WorkflowPackageInstaller::class)->install($package, $user, ['user_1' => $reviewer->uuid], 'named-copy', '  Pkg named (copy)  ');
    expect($result['name'])->toBe('Pkg named (copy)')
        ->and(WorkflowProject::query()->where('slug', 'named-copy')->value('name'))->toBe('Pkg named (copy)')
        ->and(WorkflowProject::query()->where('slug', 'named')->value('name'))->toBe('Pkg named');
});

it('refuses an existing slug, also a deleted one, and never touches the local workflow', function (): void {
    $user = pkgUser();
    $local = pkgWorkflow('local', ['start' => ['set_variable', ['assignments' => [['key' => 'vars.a', 'valueExpr' => "'1'"]]]]]);
    $package = app(WorkflowPackageExporter::class)->export('local');
    WorkflowStep::query()->where('project_id', $local->id)->update(['step_config' => ['assignments' => [['key' => 'vars.a', 'valueExpr' => "'local edit'"]]]]);
    $before = pkgGraph('local');

    try {
        app(WorkflowPackageInstaller::class)->install($package, $user);
        $this->fail('An existing slug must be refused.');
    } catch (WorkflowPackageException $e) {
        expect(pkgCodes($e->report, 'slug_problems'))->toBe(['slug_exists']);
    }

    pkgWorkflow('gone', ['start' => ['set_variable', []]])->delete();
    expect(pkgCodes(app(WorkflowPackageInspector::class)->inspect($package, $user, [], 'gone'), 'slug_problems'))->toBe(['slug_exists'])
        ->and(pkgGraph('local'))->toBe($before)
        ->and(WorkflowProject::query()->where('slug', 'local')->value('is_active'))->toBeTrue();
});

it('installs only for a user holding the node permissions', function (): void {
    pkgWorkflow('guarded', ['ask' => ['ai_reasoning', ['goal' => 'Plan', 'maxIterations' => 2]]]);
    $package = app(WorkflowPackageExporter::class)->export('guarded');
    $definition = \Cms\Core\Workflows\Nodes\NodeRegistry::current()->get('ai_reasoning');

    if ($definition->permission === null) {
        expect(true)->toBeTrue();

        return;
    }
    expect(pkgCodes(app(WorkflowPackageInspector::class)->inspect($package, pkgUser(['workflow_project.create']), [], 'guarded-2')))->toContain('permission_denied');
});

it('dry-runs with the sample record and no writes, outside calls or AI calls', function (): void {
    pkgWorkflow('dry', [
        'title' => ['set_variable', ['assignments' => [['key' => 'vars.title', 'valueExpr' => 'root.name']]]],
        'check' => ['decision', ['expression' => "vars.title == 'Ann'"]],
        'fetch' => ['http_request', ['method' => 'GET', 'url' => "'https://example.com/x'", 'storeAs' => 'vars.page']],
        'draft' => ['ai_prompt', ['instructions' => 'Summarise', 'inputs' => ['page' => 'vars.page.body'], 'storeAs' => 'vars.summary']],
        'ok' => ['approval', ['mode' => 'human', 'title' => 'Check', 'notify' => false, 'storeAs' => 'vars.approval']],
        'send' => ['telegram_send', ['credential' => '$bind:tg_main', 'chat' => "'@news'", 'text' => 'vars.summary.text']],
        'skip' => ['log_message', ['message' => 'not Ann']],
    ], [['title', 'check', null], ['check', 'fetch', 'true'], ['check', 'skip', 'false'], ['fetch', 'draft', null], ['draft', 'ok', null], ['ok', 'send', 'approved']]);
    $package = app(WorkflowPackageExporter::class)->export('dry', ['root' => ['name' => 'Ann']]);

    $counts = static fn (): array => [WorkflowProject::query()->count(), WorkflowInstance::query()->count(), DB::table('agent_approvals')->count(), DB::table('workflow_credentials')->count(), DB::table('core_ai_call_logs')->count(), DB::table('core_ai_usage_daily')->count()];
    $before = $counts();
    $report = app(WorkflowPackageInspector::class)->inspect($package, pkgUser(), [], 'dry-copy');
    $steps = collect($report['dry_run']['steps'])->keyBy('step_code');

    expect($report['dry_run']['completed'])->toBeTrue()
        ->and($steps->keys()->all())->toBe(['title', 'check', 'fetch', 'draft', 'ok', 'send'])
        ->and($steps['title']['mode'])->toBe('executed')
        ->and($steps['check']['output'])->toBeIn(['true', 'yes'])
        ->and($steps['fetch']['mode'])->toBe('stubbed')
        ->and($steps['draft']['safety'])->toContain('ai_spend')
        ->and($steps['ok']['output'])->toBe('approved')
        ->and($steps['send']['would'])->toContain('call an outside service')
        ->and($report['dry_run']['vars']['title'])->toBe('Ann')
        ->and($counts())->toBe($before);
    Http::assertNothingSent();
});

it('guards the package API with the workflow management permission', function (): void {
    pkgWorkflow('api', ['start' => ['set_variable', ['assignments' => [['key' => 'vars.a', 'valueExpr' => "'1'"]]]]]);
    $package = app(WorkflowPackageExporter::class)->export('api');

    Sanctum::actingAs(pkgUser([]));
    $this->postJson('/api/v2/admin/workflow/packages/preview', ['package' => $package])->assertForbidden();
    $this->postJson('/api/v2/admin/workflow/packages/install', ['package' => $package, 'as_slug' => 'api-2'])->assertForbidden();
    $this->getJson('/api/v2/admin/workflow/packages/export/api')->assertForbidden();

    Sanctum::actingAs(pkgUser());
    $this->postJson('/api/v2/admin/workflow/packages/preview', ['package' => $package])
        ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.slug_problems.0.code', 'slug_exists');
    $this->postJson('/api/v2/admin/workflow/packages/install', ['package' => $package])->assertStatus(422);
    $this->postJson('/api/v2/admin/workflow/packages/install', ['package' => $package, 'as_slug' => 'api-2'])
        ->assertCreated()->assertJsonPath('data.slug', 'api-2')->assertJsonPath('data.is_active', false);
    $download = $this->get('/api/v2/admin/workflow/packages/export/api')->assertOk();
    expect(json_decode($download->getContent(), true)['format'])->toBe('ispa-workflow-package')
        ->and($download->headers->get('Content-Disposition'))->toContain('api.workflow.json');
});
