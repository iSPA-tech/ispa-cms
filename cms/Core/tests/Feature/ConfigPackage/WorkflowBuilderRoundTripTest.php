<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * A workflow made in the builder (save path PUT /api/v2/admin/workflows/{id}) survives export and import through the
 * config package unchanged: steps, configs, positions, edges with their source handles, and an inactive project.
 */

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** A builder user holding the workflow permissions only. */
function rtBuilderUser(): User
{
    $user = new class extends User {
        public function permissionKeys(): array
        {
            return ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.create', 'workflow_project.update'];
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $user->forceFill(['id' => random_int(100000, 999999), 'name' => 'Builder', 'email' => uniqid('rt_', true) . '@example.com', 'password' => 'x']);
    $user->exists = true;

    return $user;
}

/**
 * Graph of a project keyed by step code, without ids or timestamps.
 *
 * @return array{steps: array<string, array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function rtGraph(WorkflowProject $project): array
{
    $steps = WorkflowStep::query()->where('project_id', $project->id)->orderBy('step_code')->get();
    $codes = $steps->pluck('step_code', 'id');

    $edges = WorkflowConnection::query()->where('project_id', $project->id)->get()
        ->map(static fn (WorkflowConnection $c): array => ['from' => $codes[$c->from_step_id], 'to' => $codes[$c->to_step_id], 'condition' => $c->condition])
        ->sortBy(static fn (array $e): string => $e['from'] . '>' . $e['to'] . '>' . ($e['condition']['source_handle'] ?? ''))
        ->values()->all();

    return [
        'steps' => $steps->mapWithKeys(static fn (WorkflowStep $s): array => [$s->step_code => [
            'name' => $s->name, 'step_type' => $s->step_type, 'step_config' => $s->step_config,
            'input_schema' => $s->input_schema, 'output_schema' => $s->output_schema,
            'position_x' => (int) $s->position_x, 'position_y' => (int) $s->position_y, 'is_start' => (bool) $s->is_start,
        ]])->all(),
        'edges' => $edges,
    ];
}

beforeEach(function (): void {
    $this->withoutMiddleware();
});

it('round-trips a builder-made workflow through the config package unchanged', function (): void {
    $assignee = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Reviewer', 'username' => 'reviewer-' . Str::lower(Str::random(6)),
        'email' => 'reviewer-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    Sanctum::actingAs(rtBuilderUser());

    $project = WorkflowProject::query()->create([
        'name' => 'Builder flow', 'slug' => 'builder-flow', 'trigger_event' => 'manual',
        'target_entity_type' => 'none', 'is_active' => true, 'tags' => ['ai'], 'description' => 'Made in the builder.',
    ]);

    $node = static fn (string $code, string $type, array $config, int $x, int $y, bool $start = false): array => [
        'id' => $code, 'type' => 'custom', 'position' => ['x' => $x, 'y' => $y],
        'data' => ['step_code' => $code, 'name' => ucfirst($code), 'step_type' => $type, 'step_config' => $config, 'is_start' => $start],
    ];
    $edge = static fn (string $from, string $to, ?string $handle = null): array => ['source' => $from, 'target' => $to] + ($handle === null ? [] : ['sourceHandle' => $handle]);

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", [
        'nodes' => [
            $node('start', 'trigger_manual', ['input_schema' => null], 0, 0, true),
            $node('ai', 'ai_prompt', ['instructions' => 'Sort the request', 'inputs' => ['text' => '{{ vars.input }}'], 'output' => 'json', 'storeAs' => 'vars.ai'], 280, 40),
            $node('route', 'switch', ['valueExpr' => 'vars.ai.data.kind', 'cases' => [['key' => 'urgent', 'match' => 'urgent']]], 560, -40),
            $node('review', 'approval', [
                'mode' => 'human', 'title' => 'Check the answer', 'payload' => 'vars.ai',
                'editable_fields' => ['data.answer'], 'reviewer_permission' => 'agentic.approve',
                'revision_options' => [['key' => 'length', 'label_key' => 'text.workflow.revision.length', 'choices' => [['value' => 'short', 'label_key' => 'text.workflow.revision.length_short']]]],
                'max_rounds' => 2, 'assignees' => [$assignee->uuid], 'sla' => '4h', 'expires_after' => '3d', 'storeAs' => 'vars.approval',
            ], 840, 120),
            $node('done', 'log_message', ['level' => 'info', 'message' => 'Approved: {{ vars.ai.data.kind }}'], 1120, 0),
            $node('failed', 'log_message', ['level' => 'warning', 'message' => 'Could not sort the request.'], 560, 300),
        ],
        'edges' => [
            $edge('start', 'ai'),
            $edge('ai', 'route'),
            $edge('ai', 'failed', 'error'),
            $edge('route', 'review', 'case:urgent'),
            $edge('route', 'done', 'default'),
            $edge('review', 'done', 'approved'),
            $edge('review', 'ai', 'changes_requested'),
        ],
    ])->assertOk();

    $original = rtGraph($project);
    expect($original['steps'])->toHaveCount(6)->and($original['edges'])->toHaveCount(7);

    $export = (new WorkflowsSection())->export(new ExportContext());
    expect($export->skipped)->toBe([]);
    $row = collect($export->rows)->firstWhere('slug', 'builder-flow');
    expect($row)->not->toBeNull();

    // The row passes the package checks (no numeric id, no secret) and imports as a new project.
    $sections = ['workflows' => [$row]];
    $package = [
        'format' => PackageFormat::FORMAT, 'format_version' => PackageFormat::VERSION, 'created_at' => '2026-11-24T10:00:00+07:00',
        'source' => ['app_name' => 'iSPA', 'host' => 'example.test', 'core_version' => '1.0.0', 'modules' => ['Core' => '1.0.0']],
        'sections' => $sections, 'required_secrets' => [], 'skipped' => [], 'checksum' => PackageFormat::checksum($sections),
    ];
    expect((new PackageValidator())->validate($package)->errors())->toBe([]);

    $row['slug'] = 'builder-flow-copy';
    $result = (new WorkflowsSection())->apply([$row], new ApplyContext());
    expect($result->items[0]['outcome'] ?? null)->toBe('created', (string) ($result->items[0]['reason'] ?? ''));

    $copy = WorkflowProject::query()->where('slug', 'builder-flow-copy')->sole();
    expect($copy->is_active)->toBeFalse()
        ->and($copy->name)->toBe('Builder flow')
        ->and($copy->tags)->toBe(['ai'])
        ->and(rtGraph($copy))->toEqual($original);

    // Importing the same row again finds nothing to change.
    expect((new WorkflowsSection())->apply([$row], new ApplyContext())->unchanged)->toBe(1);
});
