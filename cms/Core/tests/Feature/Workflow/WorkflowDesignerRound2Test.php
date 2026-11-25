<?php

declare(strict_types=1);

/*
 * Workflow designer round 2 ("pick, don't type"): the presets API, known `values` on result fields, `x-setup` markers
 * and the setup questions of an installed sample (GET/PUT api/v2/admin/workflows/{project}/setup).
 */

use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetSource;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\WorkflowScheduleService;
use Cms\Core\Workflows\Nodes\NodeDefinition;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware();
    Queue::fake();
    app()->setLocale('en');
    foreach (\App\Enums\UserStatusEnum::cases() as $case) {
        \Illuminate\Support\Facades\DB::table('enumerates')->insertOrIgnore([
            'name' => $case->value, 'enum_key' => $case->value, 'type' => 'user_status', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // User id 1 is always a webmaster; burn it.
    round2User('placeholder');
});

function round2User(string $name, array $permissions = []): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            $dot = strrpos($key, '.');
            $role->permissions()->attach(Permission::query()->firstOrCreate(
                ['group' => substr($key, 0, $dot), 'name' => substr($key, $dot + 1)],
                ['title' => $key],
            ));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

function round2Editor(): User
{
    return round2User('editor', ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.update']);
}

/**
 * A sample-like draft: manual start → AI classify → approval (no reviewer yet) → telegram (no bot yet) → notify.
 */
function round2Project(): WorkflowProject
{
    Permission::query()->firstOrCreate(['group' => 'post', 'name' => 'update'], ['title' => 'Update posts']);

    $project = WorkflowProject::query()->forceCreate([
        'name' => 'Sample', 'slug' => 'sample-' . Str::lower(Str::random(5)), 'trigger_event' => 'manual',
        'target_entity_type' => 'none', 'is_active' => false, 'tags' => ['sample'],
    ]);

    $steps = [
        'start' => ['trigger_manual', 'Start', ['storeAs' => 'vars.trigger'], true],
        'sort' => ['ai_classify', 'Sort the message', ['text' => 'vars.trigger.text', 'labels' => [], 'storeAs' => 'vars.classify'], false],
        'review' => ['approval', 'Review the reply', ['mode' => 'human', 'review' => 'vars.classify', 'editable_fields' => ['summary'], 'storeAs' => 'vars.approval'], false],
        'tell' => ['telegram_send', 'Send to the group', ['credential' => '', 'chat' => '', 'text' => 'Done', 'storeAs' => 'vars.telegram'], false],
        'notify' => ['notify', 'Tell the editors', ['permission' => 'post.update', 'message' => 'Done', 'storeAs' => 'vars.notified'], false],
    ];
    $made = [];
    $x = 0;
    foreach ($steps as $code => [$type, $name, $config, $start]) {
        $made[$code] = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_code' => $code, 'name' => $name, 'step_type' => $type,
            'step_config' => $config, 'is_start' => $start, 'position_x' => $x, 'position_y' => 0,
        ]);
        $x += 200;
    }
    foreach ([['start', 'sort', null], ['sort', 'review', 'default'], ['review', 'tell', 'approved'], ['tell', 'notify', null]] as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    return $project->fresh();
}

function round2Question(array $data, string $key): ?array
{
    return collect($data['questions'])->firstWhere('key', $key);
}

final class Round2FakeLabelSource implements WorkflowPresetSource
{
    public function items(): array
    {
        return [['key' => 'news', 'label' => 'News'], ['key' => 'events', 'label' => 'Events']];
    }
}

// ── Presets ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('serves the Core JEV criteria sets with items {key, label, text, default}', function (): void {
    $this->actingAs(round2User('editor', ['workflow_project.update']), 'sanctum');

    $sets = $this->getJson('/api/v2/admin/workflows/presets?kind=jev_criteria')->assertOk()->json('data');

    expect(array_column($sets, 'key'))->toContain('news', 'reflection', 'support_answer', 'seo_description')
        ->and(array_keys($sets[0]))->toBe(['key', 'label', 'description', 'module', 'items'])
        ->and($sets[0]['module'])->toBeNull()
        ->and(array_keys($sets[0]['items'][0]))->toBe(['key', 'label', 'text', 'default'])
        ->and($sets[0]['items'][0]['text'])->toBeString()->not->toBe('');
});

it('serves score rubrics with weights and classify label sets', function (): void {
    $this->actingAs(round2User('editor', ['workflow_project.create']), 'sanctum');

    $rubric = $this->getJson('/api/v2/admin/workflows/presets?kind=score_rubric')->assertOk()->json('data.0');
    $labels = collect($this->getJson('/api/v2/admin/workflows/presets?kind=classify_labels')->assertOk()->json('data'))->firstWhere('key', 'contact_message');

    expect(array_keys($rubric['items'][0]))->toBe(['key', 'label', 'text', 'weight'])
        ->and($labels['items'][0])->toBe(['key' => 'question', 'label' => 'Question']);
});

it('merges preset sets a module registers, including a source set read from site records', function (): void {
    config(['round2module.workflow_presets' => ['sets' => [
        'church_check' => ['kind' => 'jev_criteria', 'label' => 'Church check', 'items' => [
            ['key' => 'dates', 'label' => 'Dates', 'text' => 'Every date is sourced.', 'default' => false],
        ]],
        'site_labels' => ['kind' => 'classify_labels', 'label' => 'Site labels', 'source' => 'round2.labels', 'resolver' => Round2FakeLabelSource::class],
        'news' => ['kind' => 'jev_criteria', 'label' => 'Not the Core one', 'items' => []],
    ]]]);
    WorkflowPresetCatalog::registerModuleConfig('round2module', 'round2module.workflow_presets');
    $this->actingAs(round2User('editor', ['workflow_project.update']), 'sanctum');

    $criteria = collect($this->getJson('/api/v2/admin/workflows/presets?kind=jev_criteria')->json('data'));
    $labels = collect($this->getJson('/api/v2/admin/workflows/presets?kind=classify_labels')->json('data'));

    expect($criteria->firstWhere('key', 'church_check'))->toMatchArray(['module' => 'round2module', 'label' => 'Church check'])
        ->and($criteria->firstWhere('key', 'church_check')['items'][0]['default'])->toBeFalse()
        ->and($criteria->firstWhere('key', 'news')['module'])->toBeNull()
        ->and($labels->firstWhere('key', 'site_labels'))->toMatchArray(['source' => 'round2.labels', 'module' => 'round2module'])
        ->and($labels->firstWhere('key', 'site_labels')['items'])->toHaveCount(2);
});

it('refuses an unknown kind and a user who may not edit workflows', function (): void {
    $this->actingAs(round2User('editor', ['workflow_project.update']), 'sanctum');
    $this->getJson('/api/v2/admin/workflows/presets?kind=colours')->assertStatus(422);

    $this->actingAs(round2User('viewer', ['workflow_project.viewAny', 'workflow_project.view']), 'sanctum');
    $this->getJson('/api/v2/admin/workflows/presets?kind=jev_criteria')->assertStatus(403);
});

it('authors en + vi text for every Core preset set and item', function (): void {
    $authored = [];
    foreach (require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php') as $row) {
        $authored[$row['name']] = $row['text'];
    }

    $missing = [];
    foreach ((array) config('core.workflow_presets.sets') as $key => $set) {
        $names = ["text.workflow.preset.{$key}.title", "text.workflow.preset.{$key}.desc"];
        foreach ($set['items'] as $item) {
            $names[] = "text.workflow.preset.{$key}.{$item['key']}";
            if (isset($item['text'])) {
                $names[] = "text.workflow.preset.{$key}.{$item['key']}_text";
            }
        }
        foreach ($names as $name) {
            if (trim((string) ($authored[$name]['en'] ?? '')) === '' || trim((string) ($authored[$name]['vi'] ?? '')) === '') {
                $missing[] = $name;
            }
        }
    }

    expect($missing)->toBe([]);
});

// ── Result values and setup markers ──────────────────────────────────────────────────────────────────────────────

it('lists the known values of the JEV verdict and the approval decision', function (): void {
    $registry = NodeRegistry::current();
    $field = static fn (string $type, string $key): array => collect($registry->get($type)->toCatalogArray()['result'])->firstWhere('key', $key);

    expect(array_column($field('jev_review', 'verdict')['values'], 'value'))->toBe(['pass', 'fail', 'unsure'])
        ->and(array_column($field('jev_review', 'output')['values'], 'value'))->toBe(['pass', 'fail', 'unsure'])
        ->and(array_column($field('approval', 'decision')['values'], 'value'))->toBe(['approved', 'rejected', 'expired', 'changes_requested'])
        ->and($field('approval', 'decision')['values'][0])->toMatchArray(['label_key' => 'text.workflow.value.approved', 'label' => 'Approved'])
        ->and($field('ai_classify', 'label')['values_from'])->toBe('labels')
        ->and($field('ai_classify', 'labels')['values_from'])->toBe('labels')
        ->and($field('ai_classify', 'label'))->not->toHaveKey('values');
});

it('authors en + vi labels for every known result value', function (): void {
    $authored = [];
    foreach (require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php') as $row) {
        $authored[$row['name']] = $row['text'];
    }
    $missing = [];
    foreach (NodeRegistry::current()->all() as $definition) {
        foreach ($definition->result ?? [] as $field) {
            foreach ($field['values'] ?? [] as $value) {
                if (trim((string) ($authored[$value['label_key']]['vi'] ?? '')) === '') {
                    $missing[] = $value['label_key'];
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('refuses a values list without labels and a bad values_from', function (): void {
    expect(fn () => new NodeDefinition('sample_bad', result: [['key' => 'x', 'type' => 'string', 'label' => 'X', 'values' => [['value' => 'a']]]]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NodeDefinition('sample_bad', result: [['key' => 'x', 'type' => 'string', 'label' => 'X', 'values_from' => 'a.b']]))
        ->toThrow(InvalidArgumentException::class);
});

it('adds the optional criteria preset fields to jev_review and marks the setup fields', function (): void {
    $registry = NodeRegistry::current();
    $props = static fn (string $type): array => $registry->get($type)->configSchema['properties'];

    expect($props('jev_review'))->toHaveKeys(['criteria', 'criteria_preset', 'criteria_items'])
        ->and(WorkflowSetupService::kindOf($props('jev_review')['criteria']))->toBe('required_field')
        ->and(WorkflowSetupService::kindOf($props('approval')['reviewer_permission']))->toBe('reviewers')
        ->and(WorkflowSetupService::kindOf($props('notify')['permission']))->toBe('notify_channel')
        ->and(WorkflowSetupService::kindOf($props('telegram_send')['credential']))->toBe('credential')
        ->and(WorkflowSetupService::kindOf($props('http_request')['credential']))->toBe('credential')
        ->and(WorkflowSetupService::kindOf($props('jev_review')['content']))->toBeNull();
});

// ── Setup questions ──────────────────────────────────────────────────────────────────────────────────────────────

it('lists what the site must decide, with plain-word preset lines', function (): void {
    $project = round2Project();
    $this->actingAs(round2Editor(), 'sanctum');

    $data = $this->getJson("/api/v2/admin/workflows/{$project->id}/setup")->assertOk()->json('data');

    expect(array_column($data['questions'], 'key'))->toBe(['sort.labels', 'review.reviewer_permission', 'tell.credential', 'tell.chat', 'notify.permission', 'schedule'])
        ->and($data['complete'])->toBeFalse()
        ->and(array_keys(round2Question($data, 'review.reviewer_permission')))->toBe(['key', 'kind', 'step_code', 'field', 'label', 'help', 'required', 'answered', 'current', 'assignees_allowed'])
        ->and(round2Question($data, 'review.reviewer_permission')['assignees_allowed'])->toBeTrue()
        ->and(round2Question($data, 'review.reviewer_permission'))->toMatchArray(['kind' => 'reviewers', 'answered' => false, 'label' => 'Who reviews at “Review the reply”?'])
        ->and(round2Question($data, 'sort.labels'))->toMatchArray(['kind' => 'required_field', 'answered' => false])
        ->and(round2Question($data, 'notify.permission'))->toMatchArray(['kind' => 'notify_channel', 'answered' => true, 'current' => 'post.update'])
        ->and(round2Question($data, 'schedule'))->toMatchArray(['kind' => 'schedule', 'answered' => false])
        ->and(round2Question($data, 'schedule'))->not->toHaveKey('step_code')
        ->and(array_column($data['preset'], 'label'))->toContain('Reviewers may edit: Summary');
});

it('answers the questions on the draft, schedule through the schedule rule, never echoing a credential value', function (): void {
    $project = round2Project();
    $editor = round2Editor();
    WorkflowCredential::query()->create(['name' => 'parish-bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'secret-token-123']]);
    $this->actingAs($editor, 'sanctum');

    $response = $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => [
        'sort.labels' => [['key' => 'question', 'description' => 'Question']],
        'review.reviewer_permission' => ['permission' => 'post.update', 'assignees' => [$editor->uuid]],
        'tell.credential' => 'parish-bot',
        'tell.chat' => '-1001234',
        'schedule' => ['cron' => '0 6 * * *', 'timezone' => 'Asia/Ho_Chi_Minh'],
    ]])->assertOk();

    $data = $response->json('data');
    expect($data['complete'])->toBeTrue()
        ->and(round2Question($data, 'tell.credential')['current'])->toBe('parish-bot')
        ->and($response->getContent())->not->toContain('secret-token-123');

    $review = WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'review')->sole();
    expect($review->step_config['reviewer_permission'])->toBe('post.update')
        ->and($review->step_config['assignees'])->toBe([$editor->uuid])
        ->and(WorkflowStep::query()->where('step_code', 'tell')->where('project_id', $project->id)->sole()->step_config['credential'])->toBe('$bind:parish-bot');

    $project->refresh();
    expect($project->schedule_cron)->toBe('0 6 * * *')
        ->and($project->scheduled_by_user_id)->toBe($editor->id);
});

it('saves the reviewer group of a step that names no people even when the form sends an empty people list', function (): void {
    $project = round2Project();
    $notify = WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'notify')->sole();
    $ask = WorkflowStep::query()->create([
        'project_id' => $project->id, 'step_code' => 'ask', 'name' => 'Ask the editor', 'step_type' => 'ask_and_wait',
        'step_config' => ['question' => 'Publish?', 'storeAs' => 'vars.ask'], 'is_start' => false, 'position_x' => 1000, 'position_y' => 0,
    ]);
    WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $notify->id, 'to_step_id' => $ask->id, 'condition' => null]);
    $this->actingAs(round2Editor(), 'sanctum');

    $before = $this->getJson("/api/v2/admin/workflows/{$project->id}/setup")->assertOk()->json('data');
    expect(round2Question($before, 'ask.reviewer_permission'))->toMatchArray(['kind' => 'reviewers', 'assignees_allowed' => false]);

    $data = $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => [
        'ask.reviewer_permission' => ['permission' => 'post.update', 'assignees' => []],
    ]])->assertOk()->json('data');
    expect(round2Question($data, 'ask.reviewer_permission'))->toMatchArray(['answered' => true]);

    $errors = $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => [
        'ask.reviewer_permission' => ['permission' => 'post.update', 'assignees' => [(string) Str::uuid()]],
    ]])->assertStatus(422)->json('data.errors');
    expect($errors)->not->toBe([]);
});

it('lets an editor run the safe test of the draft without the package install right, writing nothing', function (): void {
    $project = round2Project();
    $editor = round2Editor();
    WorkflowCredential::query()->create(['name' => 'parish-bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'secret-token-123']]);
    $this->actingAs($editor, 'sanctum');

    // The empty account field of the telegram step keeps the draft from being checked until it is answered.
    $this->postJson("/api/v2/admin/workflows/{$project->id}/setup/test-run")->assertStatus(422)->assertJsonPath('error_code', 'setup_incomplete');

    $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => ['tell.credential' => 'parish-bot']])->assertOk();
    $projects = WorkflowProject::withTrashed()->count();
    $steps = WorkflowStep::query()->where('project_id', $project->id)->orderBy('id')->get(['step_code', 'step_config'])->toArray();

    $response = $this->postJson("/api/v2/admin/workflows/{$project->id}/setup/test-run")->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveKeys(['dry_run', 'problems', 'target_slug'])
        ->and($data['target_slug'])->toBe($project->slug . '-test-run')
        ->and($data['dry_run'])->toBeArray()->toHaveKey('steps')
        ->and($response->getContent())->not->toContain('secret-token-123')
        ->and(WorkflowProject::withTrashed()->count())->toBe($projects)
        ->and(WorkflowStep::query()->where('project_id', $project->id)->orderBy('id')->get(['step_code', 'step_config'])->toArray())->toBe($steps);

    $this->actingAs(round2User('viewer', ['workflow_project.viewAny', 'workflow_project.view']), 'sanctum');
    $this->postJson("/api/v2/admin/workflows/{$project->id}/setup/test-run")->assertForbidden();
});

it('records "run only by hand" as an answered schedule', function (): void {
    $project = round2Project();
    $this->actingAs(round2Editor(), 'sanctum');

    $data = $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => ['schedule' => ['cron' => null]]])->assertOk()->json('data');

    expect(round2Question($data, 'schedule'))->toMatchArray(['answered' => true, 'current' => ['cron' => null, 'timezone' => null, 'by_hand' => true]])
        ->and($project->fresh()->tags)->toContain(WorkflowScheduleService::MANUAL_TAG);
});

it('refuses invalid answers with the draft save checks and writes nothing', function (array $answers, string $code): void {
    $project = round2Project();
    $this->actingAs(round2Editor(), 'sanctum');

    $errors = $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => $answers])->assertStatus(422)->json('data.errors');

    expect(array_column($errors, 'code'))->toContain($code)
        ->and(WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'review')->sole()->step_config)->not->toHaveKey('reviewer_permission')
        ->and($project->fresh()->schedule_cron)->toBeNull();
})->with([
    'unknown assignee' => [['review.reviewer_permission' => ['permission' => 'post.update', 'assignees' => ['00000000-0000-4000-8000-000000000000']], 'schedule' => ['cron' => '0 6 * * *']], 'assignee_unknown'],
    'unknown permission' => [['review.reviewer_permission' => 'nobody.has-this'], 'answer_invalid'],
    'wrong config type' => [['review.reviewer_permission' => 'post.update', 'sort.labels' => 42], 'config_invalid'],
    'missing credential' => [['tell.credential' => 'no-such-bot'], 'answer_invalid'],
    'bad cron' => [['schedule' => ['cron' => 'every morning']], 'validation_failed'],
    'not a question' => [['review.title' => 'x'], 'unknown_question'],
]);

it('needs view to read the setup and update to answer it', function (): void {
    $project = round2Project();

    $this->actingAs(round2User('lister', ['workflow_project.viewAny']), 'sanctum');
    $this->getJson("/api/v2/admin/workflows/{$project->id}/setup")->assertStatus(403);

    $this->actingAs(round2User('viewer', ['workflow_project.viewAny', 'workflow_project.view']), 'sanctum');
    $this->getJson("/api/v2/admin/workflows/{$project->id}/setup")->assertOk();
    $this->putJson("/api/v2/admin/workflows/{$project->id}/setup", ['answers' => ['schedule' => ['cron' => null]]])->assertStatus(403);
});
