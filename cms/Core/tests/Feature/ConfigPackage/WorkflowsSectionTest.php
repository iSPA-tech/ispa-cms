<?php

declare(strict_types=1);

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    foreach (['workflow_connections', 'workflow_steps', 'workflow_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('workflow_projects', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('trigger_event')->nullable();
        $table->string('target_entity_type');
        $table->boolean('is_active')->default(true);
        $table->json('tags')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('workflow_steps', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->string('step_code')->nullable();
        $table->string('name')->nullable();
        $table->string('step_type');
        $table->json('step_config')->nullable();
        $table->json('input_schema')->nullable();
        $table->json('output_schema')->nullable();
        $table->integer('position_x')->default(0);
        $table->integer('position_y')->default(0);
        $table->boolean('is_start')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('workflow_connections', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->unsignedBigInteger('from_step_id')->nullable();
        $table->unsignedBigInteger('to_step_id')->nullable();
        $table->json('condition')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

/**
 * Create a project with steps (code => type) chained in order, returning it.
 *
 * @param array<string, string> $steps
 * @param array<string, mixed>  $attributes
 */
function wfProject(string $slug, array $steps = ['a' => 'event', 'b' => 'job'], array $attributes = []): WorkflowProject
{
    $project = WorkflowProject::create($attributes + [
        'name' => 'Flow ' . $slug,
        'slug' => $slug,
        'trigger_event' => 'order.created',
        'target_entity_type' => 'App\\Models\\Order',
        'is_active' => true,
        'tags' => ['order'],
        'description' => 'desc ' . $slug,
    ]);

    $ids = [];
    $first = true;
    foreach ($steps as $code => $type) {
        $ids[] = WorkflowStep::create([
            'project_id' => $project->id,
            'step_code' => $code,
            'name' => 'Step ' . $code,
            'step_type' => $type,
            'step_config' => ['max_tokens' => 100],
            'position_x' => 10,
            'position_y' => 20,
            'is_start' => $first,
        ])->id;
        $first = false;
    }

    for ($i = 0; $i < count($ids) - 1; ++$i) {
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $ids[$i],
            'to_step_id' => $ids[$i + 1],
            'condition' => ['if' => 'x'],
        ]);
    }

    return $project;
}

/**
 * A valid incoming workflow row.
 *
 * @param array<string, mixed> $override
 *
 * @return array<string, mixed>
 */
function wfRow(string $slug = 'new_flow', array $override = []): array
{
    return $override + [
        'slug' => $slug,
        'name' => 'New flow',
        'trigger_event' => 'order.paid',
        'target_entity_type' => 'App\\Models\\Order',
        'is_active' => true,
        'tags' => ['t'],
        'description' => 'd',
        'steps' => [
            ['step_code' => 's1', 'name' => 'One', 'step_type' => 'event', 'step_config' => ['x' => 1], 'input_schema' => null, 'output_schema' => null, 'position_x' => 1, 'position_y' => 2, 'is_start' => true],
            ['step_code' => 's2', 'name' => 'Two', 'step_type' => 'job', 'step_config' => null, 'input_schema' => null, 'output_schema' => null, 'position_x' => 3, 'position_y' => 4, 'is_start' => false],
        ],
        'connections' => [['from' => 's1', 'to' => 's2', 'condition' => null]],
    ];
}

it('exports connections by step code and no numeric ids', function (): void {
    wfProject('alpha');

    $export = (new WorkflowsSection())->export(new ExportContext());
    $row = $export->rows[0];

    expect($export->rows)->toHaveCount(1)
        ->and($row['slug'])->toBe('alpha')
        ->and($row['connections'])->toBe([['from' => 'a', 'to' => 'b', 'condition' => ['if' => 'x']]])
        ->and($row['steps'][0]['step_config'])->toBe(['max_tokens' => 100]);

    $json = json_encode($export->toArray());
    expect($json)->not->toContain('"id"')
        ->and($json)->not->toContain('project_id')
        ->and($json)->not->toContain('_step_id');
});

it('skips a whole workflow with a secret in a step config on export', function (string $key): void {
    $project = wfProject('leaky');
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'b')
        ->update(['step_config' => json_encode(['http' => ['headers' => [$key => 'abc']]])]);
    wfProject('clean');

    $export = (new WorkflowsSection())->export(new ExportContext());

    expect(array_column($export->rows, 'slug'))->toBe(['clean'])
        ->and($export->skipped)->toBe(['contains_secret' => 1])
        ->and(json_encode($export->toArray()))->not->toContain('abc');
})->with(['Authorization', 'token', 'api_key']);

it('skips a workflow with a nested secret key', function (): void {
    $project = wfProject('nested');
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'a')
        ->update(['step_config' => json_encode(['a' => ['b' => ['access_token' => 'zzz']]])]);

    $export = (new WorkflowsSection())->export(new ExportContext());

    expect($export->rows)->toBe([])->and($export->skipped)->toBe(['contains_secret' => 1]);
});

it('blocks a row with a secret-like key on import', function (): void {
    $row = wfRow('secretive');
    $row['steps'][0]['step_config'] = ['Authorization' => 'Bearer x'];

    $item = (new WorkflowsSection())->diff([$row], new DiffContext())->item('secretive');

    expect($item['action'])->toBe('blocked')->and($item['reason'])->toBe('contains_secret');
    expect((new WorkflowsSection())->apply([$row], new ApplyContext())->blocked)->toBe(1)
        ->and(WorkflowProject::query()->count())->toBe(0);
});

it('lets AI step fields such as max_tokens through', function (): void {
    $row = wfRow('ai');
    $row['steps'][0]['step_config'] = ['max_tokens' => 500, 'tokens_used' => 3];

    expect((new WorkflowsSection())->diff([$row], new DiffContext())->item('ai')['action'])->toBe('create');
});

it('blocks missing and duplicate step codes and dangling connections', function (): void {
    $missing = wfRow('missing');
    unset($missing['steps'][1]['step_code']);
    $duplicate = wfRow('duplicate');
    $duplicate['steps'][1]['step_code'] = 's1';
    $dangling = wfRow('dangling');
    $dangling['connections'][] = ['from' => 's2', 'to' => 'ghost', 'condition' => null];
    $reversed = wfRow('reversed');
    $reversed['connections'] = [['from' => 'nope', 'to' => 's1']];

    $diff = (new WorkflowsSection())->diff([$missing, $duplicate, $dangling, $reversed], new DiffContext());

    expect(array_column($diff->items, 'action'))->toBe(['blocked', 'blocked', 'blocked', 'blocked']);

    $result = (new WorkflowsSection())->apply([$missing, $duplicate, $dangling, $reversed], new ApplyContext());
    expect($result->blocked)->toBe(4)->and(WorkflowProject::query()->count())->toBe(0);
});

it('blocks a step type outside the migration enum', function (): void {
    $row = wfRow('badtype');
    $row['steps'][1]['step_type'] = 'rocket';

    $item = (new WorkflowsSection())->diff([$row], new DiffContext())->item('badtype');

    expect($item['action'])->toBe('blocked')->and($item['reason'])->toContain('step_type');
});

it('creates a new project inactive whatever the package says', function (): void {
    $section = new WorkflowsSection();
    $item = $section->diff([wfRow()], new DiffContext())->item('new_flow');

    expect($item['action'])->toBe('create')->and($item['after']['is_active'])->toBeFalse();

    $result = $section->apply([wfRow()], new ApplyContext());
    $project = WorkflowProject::query()->where('slug', 'new_flow')->firstOrFail();

    expect($result->updated)->toBe(1)
        ->and($project->is_active)->toBeFalse()
        ->and($project->steps()->count())->toBe(2)
        ->and($project->connections()->count())->toBe(1);

    $connection = $project->connections()->firstOrFail();
    expect($connection->fromStep->step_code)->toBe('s1')->and($connection->toStep->step_code)->toBe('s2');
});

it('updates only metadata of an existing project and never is_active', function (): void {
    $project = wfProject('alpha', attributes: ['is_active' => true]);
    $row = wfRow('alpha', ['name' => 'Renamed', 'is_active' => false, 'description' => 'new', 'tags' => ['z'], 'trigger_event' => 'x.y']);
    $row['steps'] = [];
    $row['connections'] = [];
    $section = new WorkflowsSection();

    $item = $section->diff([$row], new DiffContext())->item('alpha');
    expect($item['action'])->toBe('update')
        ->and($item['after']['name'])->toBe('Renamed')
        ->and($item['graph']['would_remove_steps'])->toBe(2)
        ->and($item['graph']['would_remove_connections'])->toBe(1)
        ->and($item['graph']['default_choice'])->toBeFalse();

    $result = $section->apply([$row], new ApplyContext());
    $fresh = $project->fresh();

    expect($result->updated)->toBe(1)
        ->and($fresh->name)->toBe('Renamed')
        ->and($fresh->tags)->toBe(['z'])
        ->and($fresh->trigger_event)->toBe('x.y')
        ->and($fresh->is_active)->toBeTrue()
        ->and($project->steps()->count())->toBe(2)
        ->and($project->connections()->count())->toBe(1);
});

it('replaces the graph only with the per-project choice and soft deletes the old rows', function (): void {
    $project = wfProject('alpha');
    $row = wfRow('alpha', ['name' => 'Flow alpha', 'description' => 'desc alpha', 'tags' => ['order'], 'trigger_event' => 'order.created']);
    $section = new WorkflowsSection();

    $without = $section->apply([$row], new ApplyContext());
    expect($without->skipped)->toBe(1)
        ->and($project->steps()->pluck('step_code')->all())->toBe(['a', 'b']);

    $with = $section->apply([$row], new ApplyContext(['alpha#graph' => true]));

    expect($with->updated)->toBe(1)
        ->and($project->steps()->pluck('step_code')->all())->toBe(['s1', 's2'])
        ->and(WorkflowStep::onlyTrashed()->where('project_id', $project->id)->count())->toBe(2)
        ->and(WorkflowConnection::onlyTrashed()->where('project_id', $project->id)->count())->toBe(1)
        ->and($project->connections()->count())->toBe(1)
        ->and($project->fresh()->is_active)->toBeTrue();
});

it('runs step config through the upgrader before saving', function (): void {
    $row = wfRow('legacy');
    $row['steps'][1] = ['step_code' => 's2', 'step_type' => 'decision', 'step_config' => ['condition' => 'a == b']] + $row['steps'][1];

    (new WorkflowsSection())->apply([$row], new ApplyContext());
    $config = WorkflowStep::query()->where('step_code', 's2')->firstOrFail()->step_config;

    expect($config['expression'] ?? null)->not->toBeNull();
});

it('round-trips workflows after drift without touching other projects and never deletes', function (): void {
    wfProject('alpha');
    wfProject('beta', ['x' => 'event']);
    $section = new WorkflowsSection();
    $export = $section->export(new ExportContext());

    // Drift: alpha renamed with a different graph and activated state, beta untouched, gamma appears.
    $alpha = WorkflowProject::query()->where('slug', 'alpha')->firstOrFail();
    $alpha->update(['name' => 'Drifted', 'is_active' => false]);
    WorkflowConnection::query()->where('project_id', $alpha->id)->delete();
    WorkflowStep::query()->where('project_id', $alpha->id)->where('step_code', 'b')->delete();
    wfProject('gamma');
    $betaBefore = WorkflowProject::query()->where('slug', 'beta')->firstOrFail()->updated_at;

    $result = $section->apply($export->rows, new ApplyContext(['alpha#graph' => true]));
    $after = collect($section->export(new ExportContext())->rows)->keyBy('slug');
    $before = collect($export->rows)->keyBy('slug');

    expect($result->updated)->toBe(1)->and($result->unchanged)->toBe(1)
        ->and(Illuminate\Support\Arr::except($after['alpha'], 'is_active'))->toBe(Illuminate\Support\Arr::except($before['alpha'], 'is_active'))
        ->and($after['alpha']['is_active'])->toBeFalse()
        ->and($after['beta'])->toBe($before['beta'])
        ->and(WorkflowProject::query()->where('slug', 'beta')->firstOrFail()->updated_at->equalTo($betaBefore))->toBeTrue()
        ->and($after->has('gamma'))->toBeTrue()
        ->and(WorkflowProject::query()->count())->toBe(3);
});

it('is additive: import never deletes a project', function (): void {
    wfProject('keep');

    (new WorkflowsSection())->apply([wfRow('other')], new ApplyContext());

    expect(WorkflowProject::query()->whereIn('slug', ['keep', 'other'])->count())->toBe(2);
});

it('blocks a slug that belongs to a deleted project and a slug listed twice', function (): void {
    wfProject('gone')->delete();

    $diff = (new WorkflowsSection())->diff([wfRow('gone'), wfRow('twice'), wfRow('twice')], new DiffContext());

    expect(array_column($diff->items, 'action'))->toBe(['blocked', 'blocked', 'blocked']);
});
