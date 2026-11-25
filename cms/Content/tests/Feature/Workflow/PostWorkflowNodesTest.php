<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use App\Models\User;
use Cms\Content\Models\Post;
use Cms\Content\Services\Agentic\PostDraftArtifactApplier;
use Cms\Content\Services\Workflow\PostDraftWriter;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Category;
use Cms\Core\Models\Tag;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;
use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(\Tests\TestCase::class);
uses(RefreshDatabase::class);

/**
 * The user a run acts for: an editor holding post.create, post.update and tag.create, or (`$editor` false) a user
 * without any role.
 */
function contentNodeUser(bool $editor = true): User
{
    $user = User::factory()->create();
    if ($editor) {
        $roleId = DB::table('roles')->insertGetId(['name' => 'Editor ' . $user->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['post', 'create'], ['post', 'update'], ['tag', 'create']] as [$group, $name]) {
            $permissionId = DB::table('permissions')->where('group', $group)->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['group' => $group, 'name' => $name, 'title' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user;
}

/** @param array<string, array{0: string, 1: array<string, mixed>}> $steps @param list<array{0: string, 1: string, 2: ?string}> $edges */
function contentNodeRun(array $steps, array $edges, User $user): WorkflowInstance
{
    $project = WorkflowProject::query()->create(['name' => 'Content nodes', 'slug' => 'content-nodes-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $made = [];
    $x = 1;
    foreach ($steps as $code => [$type, $config]) {
        $made[$code] = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_type' => $type, 'step_code' => $code,
            'step_config' => $config, 'position_x' => $x, 'is_start' => $x === 1,
        ]);
        $x++;
    }
    foreach ($edges as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => $user->id, 'status' => 'pending', 'delegated_by_user_id' => $user->id]);
    app(WorkflowGraphExecutor::class)->execute($instance->id);

    return $instance->fresh();
}

/** Draft → Core `approval` node (`$mode`: human, auto) reviewing the draft envelope → publish on `approved`. */
function contentDraftGraph(string $mode = 'human'): array
{
    return [
        [
            'save' => ['content.save_post_draft', [
                'title' => "'Hello workflow'", 'description' => "'Short summary'", 'content' => "'<p>Body</p>'",
                'category' => "'News'", 'tags' => "['alpha', 'beta']", 'create_missing_tags' => true, 'storeAs' => 'vars.draft',
            ]],
            'ok' => ['approval', ['mode' => $mode, 'review' => 'vars.draft.review', 'notify' => false, 'editable_fields' => ['title']]],
            'publish' => ['content.publish_post', ['post_uuid' => 'vars.draft.post_uuid', 'storeAs' => 'vars.published']],
        ],
        [['save', 'ok', null], ['ok', 'publish', 'approved']],
    ];
}

function contentStepStatus(WorkflowInstance $instance, string $code): ?string
{
    $step = WorkflowStep::query()->where('project_id', $instance->project_id)->where('step_code', $code)->first();

    return WorkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $step?->id)->value('status');
}

/**
 * On SQLite the Core migration that moves `workflow_instance_steps.step_id` from workflow_instances to workflow_steps
 * cannot drop the old foreign key, so both stay and a step id must also be an instance id. Rebuild the table without
 * the stale key (test database only).
 */
function contentFixInstanceStepKey(): void
{
    if (DB::getDriverName() !== 'sqlite') {
        return;
    }

    $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'workflow_instance_steps')->value('sql');
    $fixed = preg_replace('/,\s*foreign key\("step_id"\) references "workflow_instances"\("id"\)[^,)]*/i', '', $sql);
    if ($fixed === null || $fixed === $sql) {
        return;
    }

    // Build the fixed table under a new name and rename it into place: renaming the old table away would also rewrite
    // other tables' foreign keys (agent_runs.parent_workflow_step_id) to the old name.
    $fixed = preg_replace('/^CREATE TABLE "?workflow_instance_steps"?/i', 'CREATE TABLE "workflow_instance_steps_fixed"', $fixed);
    DB::statement($fixed);
    DB::statement('INSERT INTO workflow_instance_steps_fixed SELECT * FROM workflow_instance_steps');
    DB::statement('DROP TABLE workflow_instance_steps');
    DB::statement('ALTER TABLE workflow_instance_steps_fixed RENAME TO workflow_instance_steps');
}

beforeEach(function (): void {
    contentFixInstanceStepKey();
    foreach ([StatusEnum::DRAFT->value, StatusEnum::PUBLISHED->value] as $key) {
        DB::table('enumerates')->insertOrIgnore(['enum_key' => $key, 'type' => 'status', 'name' => $key]);
    }
    DB::table('languages')->insertOrIgnore(['id' => 1, 'name' => 'Vietnamese', 'code' => 'vi', 'path' => 'vi', 'default_web' => true]);
    Category::query()->create(['name' => 'News', 'type' => 0, 'language_id' => 1])->forceFill(['uuid' => (string) Str::uuid()])->save();
});

it('registers the three content nodes with their safety and permission', function (): void {
    $registry = NodeRegistry::current();

    expect($registry->get('content.save_post_draft'))->not->toBeNull()
        ->and($registry->get('content.save_post_draft')->safety)->toBe(['internal_write'])
        ->and($registry->get('content.save_post_draft')->permission)->toBe('post.create')
        ->and($registry->get('content.publish_post')->safety)->toBe(['publish'])
        ->and($registry->get('content.publish_post')->approvalDefault)->toBe('required')
        ->and($registry->get('content.assign_taxonomy')->permission)->toBe('post.update')
        ->and(app(StepRunnerRegistry::class)->has('content.publish_post'))->toBeTrue();
});

it('saves a draft, waits for approval and publishes it when approved', function (): void {
    [$steps, $edges] = contentDraftGraph('human');
    $user = contentNodeUser();
    $instance = contentNodeRun($steps, $edges, $user);

    expect($instance->status)->toBe('awaiting_approval')
        ->and(Post::query()->where('name', 'Hello workflow')->value('status'))->toBe(StatusEnum::DRAFT->value);

    // The reviewer edits the title in the inbox, then approves and applies; the run resumes at the approval node.
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    expect(data_get($approval->diff_data, 'workflow_node.editable_fields'))->toBe(['proposed_change.title']);
    $outcome = app(AgentApprovalService::class)->approveAndApply($approval, $user, 'Looks good', ['proposed_change.title' => 'Hello workflow']);
    expect($outcome['outcome'])->toBe('applied');
    $instance->refresh();

    expect(WorkflowInstanceStep::query()->where('instance_id', $instance->id)->whereNotNull('error_message')->value('error_message'))->toBeNull()
        ->and($instance->status)->toBe('completed');
    $post = Post::query()->where('name', 'Hello workflow')->firstOrFail();
    expect($post->status)->toBe(StatusEnum::PUBLISHED->value)
        ->and($post->published_at)->not->toBeNull()
        ->and($post->user_id)->toBe($user->id)
        ->and($post->category?->name)->toBe('News')
        ->and($post->tags()->pluck('name')->sort()->values()->all())->toBe(['alpha', 'beta'])
        ->and($post->slugs()->value('slug'))->toBe('hello-workflow')
        ->and($post->content)->toBe([['type' => 'text', 'value' => '<p>Body</p>']]);
});

it('leaves the draft unpublished when the approval is rejected', function (): void {
    [$steps, $edges] = contentDraftGraph('human');
    $user = contentNodeUser();
    $instance = contentNodeRun($steps, $edges, $user);
    app(AgentApprovalService::class)->reject(AgentApproval::query()->latest('id')->firstOrFail(), $user, 'Not now');
    $instance->refresh();

    expect($instance->status)->toBe('completed')
        ->and(data_get($instance->context_snapshot, 'vars.approval.decision'))->toBe('rejected')
        ->and(contentStepStatus($instance, 'publish'))->toBeNull()
        ->and(Post::query()->where('name', 'Hello workflow')->value('status'))->toBe(StatusEnum::DRAFT->value);
});

it('publishes at once in auto approval mode and logs the automatic decision', function (): void {
    [$steps, $edges] = contentDraftGraph('auto');
    $instance = contentNodeRun($steps, $edges, contentNodeUser());

    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    expect($instance->status)->toBe('completed')
        ->and(Post::query()->where('name', 'Hello workflow')->value('status'))->toBe(StatusEnum::PUBLISHED->value)
        ->and($approval->status)->toBe('approved')
        ->and($approval->apply_outcome)->toBe('applied')
        ->and($approval->reviewed_by_user_id)->toBeNull()
        ->and(data_get($approval->diff_data, 'workflow_node.decided_by'))->toBe('auto');
});

it('refuses to publish a post that is not a draft', function (): void {
    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Live already']);
    app(PostDraftWriter::class)->publish($post->uuid);

    $instance = contentNodeRun(['publish' => ['content.publish_post', ['post_uuid' => "'{$post->uuid}'"]]], [], contentNodeUser());

    expect(contentStepStatus($instance, 'publish'))->toBe('failed')
        ->and($instance->status)->toBe('failed');
});

it('refuses to update a published post through save_post_draft', function (): void {
    $writer = app(PostDraftWriter::class);
    $post = $writer->publish($writer->saveDraft(['title' => 'Public'])->uuid);

    expect(fn () => $writer->saveDraft(['title' => 'Changed'], $post->uuid))->toThrow(InvalidArgumentException::class, 'not a draft');
    expect($post->fresh()->name)->toBe('Public');
});

it('fails the node when the user the run acts for lacks the permission', function (): void {
    $instance = contentNodeRun(['save' => ['content.save_post_draft', ['title' => "'Nope'"]]], [], contentNodeUser(false));

    expect(contentStepStatus($instance, 'save'))->toBe('failed')
        ->and(Post::query()->where('name', 'Nope')->exists())->toBeFalse();
});

it('assigns taxonomy and creates tags only when allowed', function (): void {
    $writer = app(PostDraftWriter::class);
    $post = $writer->saveDraft(['title' => 'Tag me']);
    Tag::query()->create(['name' => 'Known', 'slug' => 'known', 'type' => Tag::TYPE_POST]);

    $result = $writer->assignTaxonomy($post->uuid, ['News'], ['Known'], false);
    expect($result['tag_ids'])->toHaveCount(1)->and($post->fresh()->category?->name)->toBe('News');

    expect(fn () => $writer->assignTaxonomy($post->uuid, null, ['Brand new'], false))->toThrow(InvalidArgumentException::class, 'does not exist');

    config(['content.workflow_nodes.allow_create_missing_tags' => false]);
    $instance = contentNodeRun(['tax' => ['content.assign_taxonomy', [
        'post_uuid' => "'{$post->uuid}'", 'tags' => "['Brand new']", 'mode' => 'add', 'create_missing_tags' => true,
    ]]], [], contentNodeUser());
    expect(contentStepStatus($instance, 'tax'))->toBe('failed')
        ->and(Tag::query()->where('name', 'Brand new')->exists())->toBeFalse();

    config(['content.workflow_nodes.allow_create_missing_tags' => true]);
    $instance = contentNodeRun(['tax' => ['content.assign_taxonomy', [
        'post_uuid' => "'{$post->uuid}'", 'tags' => "['Brand new']", 'mode' => 'add', 'create_missing_tags' => true,
    ]]], [], contentNodeUser());
    expect($instance->status)->toBe('completed')
        ->and($post->fresh()->tags()->pluck('name')->sort()->values()->all())->toBe(['Brand new', 'Known']);
});

it('builds a readable diff of the draft review and applies an edited approval', function (): void {
    $instance = contentNodeRun(['save' => ['content.save_post_draft', [
        'title' => "'Proposed'", 'content' => "'<p>Text</p>'", 'category' => "'News'", 'storeAs' => 'vars.draft',
    ]]], [], contentNodeUser());
    $step = WorkflowStep::query()->where('project_id', $instance->project_id)->where('step_code', 'save')->firstOrFail();
    $review = data_get(WorkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $step->id)->first()?->result_data, 'result.payload.review');
    expect($review)->toBeArray();

    $artifact = new AgentArtifact([
        'artifact_type' => $review['artifact_type'], 'subject_type' => $review['subject_type'], 'subject_id' => $review['subject_id'],
        'original_data_snapshot' => $review['original_data_snapshot'], 'payload' => $review['payload'],
    ]);
    $diff = app(AgentApprovalDiffBuilder::class)->build($artifact);

    expect($review['artifact_type'])->toBe(PostDraftArtifactApplier::ARTIFACT_TYPE)
        ->and($diff['field'])->toBe('post_draft')
        ->and($diff['is_new'])->toBeTrue()
        ->and($diff['after']['category'])->toBe('News')
        ->and($diff['after']['content'])->toBe('<p>Text</p>')
        ->and(collect($diff['changes'])->pluck('field'))->toContain('title', 'content', 'category');

    $payload = $review['payload'];
    $payload['proposed_change']['title'] = 'Edited by reviewer';
    $artifact->payload = $payload;
    $applied = app(PostDraftArtifactApplier::class)->apply($artifact, new AgentApproval(['reviewed_by_user_id' => 1]));

    $post = Post::query()->where('uuid', $applied['post_uuid'])->firstOrFail();
    expect($post->name)->toBe('Edited by reviewer')->and($post->status)->toBe(StatusEnum::DRAFT->value);

    // The draft changed since the proposal: a second apply of the same artifact is stale.
    expect(fn () => app(PostDraftArtifactApplier::class)->apply($artifact, new AgentApproval()))->toThrow(ApprovalStaleException::class);
});

it('lets AI text into a draft without review but needs an approval before publishing it', function (): void {
    // drafts are not reviewed, only the release is.
    $ai = ['step_code' => 'ai', 'step_type' => 'ai_reasoning', 'step_config' => ['goal' => 'write']];
    $save = ['step_code' => 'save', 'step_type' => 'content.save_post_draft', 'step_config' => ['title' => 'steps.ai.answer', 'storeAs' => 'vars.draft']];
    $ok = ['step_code' => 'ok', 'step_type' => 'approval', 'step_config' => ['mode' => 'human']];
    $publish = ['step_code' => 'publish', 'step_type' => 'content.publish_post', 'step_config' => ['post_uuid' => 'vars.draft.post_uuid']];

    expect(NodeRegistry::current()->get('content.save_post_draft')->approvalDefault)->toBe('off')
        ->and((new NodeConfigValidator())->validate([$ai, $save], [['from' => 'ai', 'to' => 'save']], null))->toBe([]);

    $problems = (new NodeConfigValidator())->validate([$ai, $save, $publish], [['from' => 'ai', 'to' => 'save'], ['from' => 'save', 'to' => 'publish']], null);
    expect(collect($problems)->pluck('step_code')->all())->toBe(['publish'])
        ->and(collect($problems)->pluck('code')->all())->toBe(['taint_needs_approval']);

    $problems = (new NodeConfigValidator())->validate([$ai, $save, $ok, $publish], [['from' => 'ai', 'to' => 'save'], ['from' => 'save', 'to' => 'ok'], ['from' => 'ok', 'to' => 'publish']], null);
    expect($problems)->toBe([]);
});

/** A user holding exactly the given `post.*` permissions (`['view', 'update']`). */
function contentBusinessUser(array $names): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->insertGetId(['name' => 'Business ' . $user->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($names as $name) {
        $permissionId = DB::table('permissions')->where('group', 'post')->where('name', $name)->value('id')
            ?? DB::table('permissions')->insertGetId(['group' => 'post', 'name' => $name, 'title' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }
    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);

    return $user;
}

it('registers the business content nodes with their safety, approval and permission', function (): void {
    $registry = NodeRegistry::current();

    expect($registry->get('content.read_post')->safety)->toBe(['read'])
        ->and($registry->get('content.read_post')->taintOutput)->toBeTrue()
        ->and($registry->get('content.read_post')->permission)->toBe('post.view')
        ->and($registry->get('content.propose_post_seo')->approvalDefault)->toBe('required')
        ->and($registry->get('content.propose_post_seo')->safety)->toBe(['internal_write'])
        ->and($registry->get('content.propose_post_seo')->taintSink)->toBeFalse()
        ->and($registry->get('content.propose_post_description')->approvalDefault)->toBe('required')
        ->and($registry->get('content.propose_post_description')->permission)->toBe('post.update');
});

it('validates the business content node configs', function (): void {
    $validator = new NodeConfigValidator();
    $problems = $validator->validate([
        ['step_code' => 'read', 'step_type' => 'content.read_post', 'step_config' => ['storeAs' => 'vars.post']],
        ['step_code' => 'desc', 'step_type' => 'content.propose_post_description', 'step_config' => ['post_uuid' => 'vars.post.post_uuid']],
    ], [], null);

    expect(collect($problems)->pluck('code')->all())->toBe(['config_invalid', 'config_invalid']);
});

it('reads a post as plain text', function (): void {
    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Readable', 'description' => 'Short', 'content' => '<p>Hello <b>world</b></p>']);

    $instance = contentNodeRun(['read' => ['content.read_post', ['post_uuid' => "'{$post->uuid}'", 'storeAs' => 'vars.post']]], [], contentBusinessUser(['view']));

    expect($instance->status)->toBe('completed')
        ->and(data_get($instance->context_snapshot, 'vars.post.post_uuid'))->toBe($post->uuid)
        ->and(data_get($instance->context_snapshot, 'vars.post.title'))->toBe('Readable')
        ->and(data_get($instance->context_snapshot, 'vars.post.text'))->toContain('Hello world');
});

it('fails read_post for an unknown post and without post.view', function (): void {
    $missing = contentNodeRun(['read' => ['content.read_post', ['post_uuid' => "'" . Str::uuid() . "'"]]], [], contentBusinessUser(['view']));
    expect(contentStepStatus($missing, 'read'))->toBe('failed');

    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Secret']);
    $denied = contentNodeRun(['read' => ['content.read_post', ['post_uuid' => "'{$post->uuid}'"]]], [], contentBusinessUser(['update']));
    expect(contentStepStatus($denied, 'read'))->toBe('failed');
});

it('writes nothing when proposing a description and applies it only after a person approves', function (): void {
    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Excerpt me', 'description' => 'Old']);
    $user = contentBusinessUser(['view', 'update']);
    $instance = contentNodeRun([
        'propose' => ['content.propose_post_description', ['post_uuid' => "'{$post->uuid}'", 'description' => "'New excerpt'", 'storeAs' => 'vars.proposal']],
        'ok' => ['approval', ['mode' => 'human', 'review' => 'vars.proposal.review', 'notify' => false]],
    ], [['propose', 'ok', null]], $user);

    expect($instance->status)->toBe('awaiting_approval')
        ->and($post->fresh()->description)->toBe('Old');

    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $outcome = app(AgentApprovalService::class)->approveAndApply($approval, $user, 'ok');
    expect($outcome['outcome'])->toBe('applied')
        ->and($post->fresh()->description)->toBe('New excerpt');
});

it('proposes SEO without writing it and refuses an automatic decision', function (): void {
    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Seo me']);
    $user = contentBusinessUser(['view', 'update']);
    $seoBefore = DB::table('seos')->count();

    $instance = contentNodeRun([
        'propose' => ['content.propose_post_seo', ['post_uuid' => "'{$post->uuid}'", 'title' => "'Better title'", 'description' => "'Better description'", 'storeAs' => 'vars.proposal']],
        'ok' => ['approval', ['mode' => 'auto', 'review' => 'vars.proposal.review', 'notify' => false]],
    ], [['propose', 'ok', null]], $user);

    expect(data_get($instance->context_snapshot, 'vars.proposal.proposed.title'))->toBe('Better title')
        ->and(contentStepStatus($instance, 'ok'))->toBe('failed')
        ->and(DB::table('seos')->count())->toBe($seoBefore);
});

it('fails the proposal nodes without post.update', function (): void {
    $post = app(PostDraftWriter::class)->saveDraft(['title' => 'Locked']);
    $instance = contentNodeRun(['propose' => ['content.propose_post_seo', ['post_uuid' => "'{$post->uuid}'"]]], [], contentBusinessUser(['view']));

    expect(contentStepStatus($instance, 'propose'))->toBe('failed');
});

it('lints AI text into a proposal as fine, and read_post text through a draft into a publish as needing approval', function (): void {
    $ai = ['step_code' => 'ai', 'step_type' => 'ai_reasoning', 'step_config' => ['goal' => 'seo']];
    $propose = ['step_code' => 'propose', 'step_type' => 'content.propose_post_seo', 'step_config' => ['post_uuid' => "'x'", 'title' => 'steps.ai.answer', 'storeAs' => 'vars.p']];
    $ok = ['step_code' => 'ok', 'step_type' => 'approval', 'step_config' => ['mode' => 'human', 'review' => 'vars.p.review']];
    expect((new NodeConfigValidator())->validate([$ai, $propose, $ok], [['from' => 'ai', 'to' => 'propose'], ['from' => 'propose', 'to' => 'ok']], null))->toBe([]);

    $read = ['step_code' => 'read', 'step_type' => 'content.read_post', 'step_config' => ['post_uuid' => "'x'", 'storeAs' => 'vars.post']];
    $save = ['step_code' => 'save', 'step_type' => 'content.save_post_draft', 'step_config' => ['title' => 'vars.post.title', 'storeAs' => 'vars.draft']];
    $publish = ['step_code' => 'publish', 'step_type' => 'content.publish_post', 'step_config' => ['post_uuid' => 'vars.draft.post_uuid']];
    expect((new NodeConfigValidator())->validate([$read, $save], [['from' => 'read', 'to' => 'save']], null))->toBe([]);
    $problems = (new NodeConfigValidator())->validate([$read, $save, $publish], [['from' => 'read', 'to' => 'save'], ['from' => 'save', 'to' => 'publish']], null);
    expect(collect($problems)->pluck('code'))->toContain('taint_needs_approval');
});
