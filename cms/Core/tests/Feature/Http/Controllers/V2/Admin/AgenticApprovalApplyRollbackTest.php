<?php

use App\Models\User;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * A failing applier leaves no half-written change behind, a retry stays possible, and approve-and-apply reports the
 * real state when another reviewer applied the approval first.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    RollbackTestApplier::$fail = true;
    RollbackTestApplier::$calls = 0;
    config(['core.agentic.artifact_appliers.test_rollback_patch' => RollbackTestApplier::class]);

    Schema::dropIfExists('apply_rollback_rows');
    Schema::create('apply_rollback_rows', function (Blueprint $table) {
        $table->id();
        $table->string('body');
    });

    // User id 1 is always a webmaster; burn it so the reviewer below is ordinary staff.
    rollbackUser('placeholder');
});

class RollbackTestApplier implements ArtifactApplierInterface
{
    public static bool $fail = true;

    public static int $calls = 0;

    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        self::$calls++;
        DB::table('apply_rollback_rows')->insert(['body' => 'half written']);

        if (self::$fail) {
            throw new RuntimeException('Second write failed.');
        }

        return ['message' => 'applied'];
    }
}

function rollbackUser(string $name): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ])->fresh();
}

function seedRollbackApproval(string $status = 'approved'): AgentApproval
{
    $profile = AgentProfile::query()->create([
        'name' => 'Story editor',
        'slug' => 'story-editor-' . Str::lower(Str::random(4)),
        'capability_class' => 'class_3_autonomous_maintenance',
        'status' => 'active',
    ]);

    $instance = WorkflowInstance::query()->create([
        'target_type' => 'test_subject',
        'status' => 'awaiting_approval',
        'resume_step_id' => 42,
    ]);

    $run = AgentRun::query()->create([
        'agent_profile_id' => $profile->id,
        'workflow_instance_id' => $instance->id,
        'module_scope' => 'test',
        'target_type' => 'test_subject',
        'target_id' => '7',
        'status' => 'awaiting_approval',
        'input_goal' => 'Write a story',
        'started_at' => now(),
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'test_rollback_patch',
        'subject_type' => 'test_subject',
        'subject_id' => '7',
        'source_snapshot_ref' => 'test_subject:7',
        'original_data_snapshot' => ['body' => 'old'],
        'payload' => ['proposed_change' => ['body' => 'new']],
        'storage_driver' => 'database',
        'version' => 1,
        'render_hint' => 'text_diff',
        'apply_contract_version' => 'v1',
    ]);

    return AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => $status,
        'diff_data' => ['before' => 'old', 'after' => 'new'],
    ]);
}

test('an applier that throws midway leaves none of its writes and records apply_failed', function () {
    $approval = seedRollbackApproval();
    $applier = rollbackUser('applier');

    app(AgentApprovalService::class)->apply($approval, $applier);

    $fresh = $approval->fresh();
    expect(DB::table('apply_rollback_rows')->count())->toBe(0)
        ->and($fresh->status)->toBe('approved')
        ->and($fresh->apply_outcome)->toBe('apply_failed')
        ->and($fresh->apply_error)->toBe('Second write failed.');
});

test('a retry after a failed apply writes the change exactly once', function () {
    $approval = seedRollbackApproval();
    $applier = rollbackUser('applier');
    $service = app(AgentApprovalService::class);

    $service->apply($approval, $applier);
    RollbackTestApplier::$fail = false;
    $service->apply($approval, $applier);

    expect(DB::table('apply_rollback_rows')->count())->toBe(1)
        ->and($approval->fresh()->apply_outcome)->toBe('applied')
        ->and(RollbackTestApplier::$calls)->toBe(2);
});

test('approve-and-apply reports applied when another reviewer applied in between, without writing an error', function () {
    RollbackTestApplier::$fail = false;
    $approval = seedRollbackApproval('pending');
    $reviewer = rollbackUser('reviewer');

    // Another reviewer applies the approval between this reviewer's approve and apply.
    $service = new class(...rollbackServiceArgs()) extends AgentApprovalService {
        public function approve(AgentApproval $approval, ?User $reviewer = null, ?string $reviewerNote = null): array
        {
            $result = parent::approve($approval, $reviewer, $reviewerNote);
            AgentApproval::query()->whereKey($approval->id)->update([
                'apply_outcome' => 'applied',
                'apply_finished_at' => now(),
            ]);

            return $result;
        }
    };

    $result = $service->approveAndApply($approval, $reviewer);

    $fresh = $approval->fresh();
    expect($result['outcome'])->toBe('applied')
        ->and($fresh->apply_outcome)->toBe('applied')
        ->and($fresh->apply_error)->toBeNull()
        ->and(RollbackTestApplier::$calls)->toBe(0);
});

/**
 * Constructor arguments of AgentApprovalService, resolved from the container.
 *
 * @return list<mixed>
 */
function rollbackServiceArgs(): array
{
    $ctor = (new ReflectionClass(AgentApprovalService::class))->getConstructor();

    return array_map(
        static fn (ReflectionParameter $p) => app((string) $p->getType()),
        $ctor ? $ctor->getParameters() : [],
    );
}
