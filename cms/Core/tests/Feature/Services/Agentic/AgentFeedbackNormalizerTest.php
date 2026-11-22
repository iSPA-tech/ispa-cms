<?php

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentMemory;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Services\Agentic\AgentFeedbackNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    // Create agent_profiles table (required FK for agent_runs)
    if (! Schema::hasTable('agent_profiles')) {
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('capability_class', 64)->index();
            $table->string('status', 32)->default('active');
            $table->longText('system_prompt_template')->nullable();
            $table->json('model_config')->nullable();
            $table->json('budget_config')->nullable();
            $table->timestamps();
        });
    }

    // Create workflow tables (required FK for agent_runs)
    if (! Schema::hasTable('workflow_projects')) {
        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('workflows')) {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('workflow_instances')) {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('pending');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('workflow_instance_steps')) {
        Schema::create('workflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    // Create agent_runs table
    if (! Schema::hasTable('agent_runs')) {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_profile_id')->nullable();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->unsignedBigInteger('parent_workflow_step_id')->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->string('trigger_mode', 32)->nullable();
            $table->string('trigger_reference_type', 64)->nullable();
            $table->string('trigger_reference_id', 191)->nullable();
            $table->string('environment', 32)->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->string('module_scope', 64)->nullable();
            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->string('status', 32)->default('init');
            $table->text('input_goal')->nullable();
            $table->json('working_memory')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->longText('reasoning_summary')->nullable();
            $table->json('budget_config')->nullable();
            $table->json('budget_usage')->nullable();
            $table->string('terminal_reason', 191)->nullable();
            $table->string('post_approval_outcome', 64)->nullable();
            $table->string('operator_request_state')->nullable();
            $table->unsignedBigInteger('stop_requested_by_user_id')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('stop_acknowledged_at')->nullable();
            $table->unsignedBigInteger('stopped_by_user_id')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_checkpoint_at')->nullable();
            $table->timestamps();
        });
    }

    // Create agent_artifacts table
    if (! Schema::hasTable('agent_artifacts')) {
        Schema::create('agent_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('artifact_group_id')->nullable();
            $table->unsignedBigInteger('parent_artifact_id')->nullable();
            $table->string('artifact_type', 64);
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('source_snapshot_ref')->nullable();
            $table->json('original_data_snapshot')->nullable();
            $table->json('payload')->nullable();
            $table->string('storage_driver', 64)->nullable();
            $table->string('storage_ref')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('render_hint', 64)->nullable();
            $table->string('apply_contract_version', 64)->default('v1');
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });
    }

    // Create agent_approvals table
    if (! Schema::hasTable('agent_approvals')) {
        Schema::create('agent_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('artifact_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('diff_data')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('apply_outcome', 64)->nullable();
            $table->text('apply_error')->nullable();
            $table->timestamp('apply_started_at')->nullable();
            $table->timestamp('apply_finished_at')->nullable();
            $table->timestamps();
        });
    }

    // Create agent_memories table
    if (! Schema::hasTable('agent_memories')) {
        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 191)->nullable();
            $table->string('entity_id', 191)->nullable();
            $table->string('memory_class', 64)->nullable();
            $table->string('memory_type', 64)->nullable();
            $table->string('context_key', 191);
            $table->text('content')->nullable();
            $table->string('embedding_ref')->nullable();
            $table->json('source_citation')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('decay_score', 6, 4)->nullable();
            $table->string('quality_state', 32)->default('active');
            $table->timestamp('promoted_to_policy_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
});

// ── captureRejectionFeedback: empty note returns null ─────────

test('captureRejectionFeedback returns null for empty note', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'content',
        'target_type' => 'App\\Models\\Post',
        'target_id' => 99,
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'content_post_description_patch',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
    ]);

    expect($normalizer->captureRejectionFeedback($approval, ''))->toBeNull();
    expect($normalizer->captureRejectionFeedback($approval, null))->toBeNull();
    expect($normalizer->captureRejectionFeedback($approval, '   '))->toBeNull();

    expect(AgentMemory::query()->count())->toBe(0);
});

// ── captureRejectionFeedback: forbidden source by prefix ──────

test('captureRejectionFeedback classifies forbidden source by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'content',
        'target_type' => 'App\\Models\\Post',
        'target_id' => 42,
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'post_seo',
        'subject_type' => 'App\\Models\\Post',
        'subject_id' => 42,
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
        'reviewed_by_user_id' => 1,
    ]);

    $memory = $normalizer->captureRejectionFeedback($approval, 'source: do not cite Wikipedia');

    expect($memory)->toBeInstanceOf(AgentMemory::class);
    expect($memory->memory_type)->toBe('forbidden_source');
    expect($memory->memory_class)->toBe('feedback_overlay');
    expect($memory->confidence_score)->toBe('0.9200');
    expect($memory->content)->toBe('source: do not cite Wikipedia');
    expect($memory->entity_type)->toBe('App\\Models\\Post');
    expect($memory->entity_id)->toBe('42');
    expect($memory->context_key)->toContain('approval_feedback.content.post_seo.forbidden_source');
    expect($memory->quality_state)->toBe('active');
    expect($memory->source_citation)->toBeArray();
    expect($memory->source_citation['approval_id'])->toBe($approval->id);
    expect($memory->source_citation['run_id'])->toBe($run->id);
});

// ── captureRejectionFeedback: reviewer preference by prefix ───

test('captureRejectionFeedback classifies reviewer preference by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'product',
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'product_description',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
    ]);

    $memory = $normalizer->captureRejectionFeedback($approval, 'style: keep tone professional');

    expect($memory)->toBeInstanceOf(AgentMemory::class);
    expect($memory->memory_type)->toBe('reviewer_preference');
    expect($memory->confidence_score)->toBe('0.9000');
    expect($memory->context_key)->toContain('reviewer_preference');
});

// ── captureRejectionFeedback: field correction by prefix ──────

test('captureRejectionFeedback classifies field correction by prefix', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'education',
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'eval_score',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
    ]);

    $memory = $normalizer->captureRejectionFeedback($approval, 'field: score must be between 0 and 10');

    expect($memory)->toBeInstanceOf(AgentMemory::class);
    expect($memory->memory_type)->toBe('field_correction');
    expect($memory->confidence_score)->toBe('0.8800');
    expect($memory->context_key)->toContain('field_correction');
    // field_correction gets 90-day TTL
    expect(now()->diffInDays($memory->expires_at))->toBeGreaterThanOrEqual(89);
});

// ── captureRejectionFeedback: general rejection fallback ──────

test('captureRejectionFeedback classifies general rejection as fallback', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'content',
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'summary',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
    ]);

    $memory = $normalizer->captureRejectionFeedback($approval, 'I just do not like this output');

    expect($memory)->toBeInstanceOf(AgentMemory::class);
    expect($memory->memory_type)->toBe('general_rejection');
    expect($memory->confidence_score)->toBe('0.5500');
    expect($memory->context_key)->toContain('general_rejection');
    // general_rejection gets 30-day TTL
    expect(now()->diffInDays($memory->expires_at))->toBeGreaterThanOrEqual(29);
    expect(now()->diffInDays($memory->expires_at))->toBeLessThanOrEqual(31);
});

// ── captureRejectionFeedback: keyword-based classification ────

test('captureRejectionFeedback classifies forbidden source by keyword', function () {
    $normalizer = new AgentFeedbackNormalizer();

    $run = AgentRun::query()->create([
        'agent_profile_id' => null,
        'status' => 'running',
        'module_scope' => 'content',
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'post_body',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'rejected',
    ]);

    // Contains 'hallucinated' keyword but no prefix
    $memory = $normalizer->captureRejectionFeedback($approval, 'This content is hallucinated and inaccurate');

    expect($memory)->toBeInstanceOf(AgentMemory::class);
    expect($memory->memory_type)->toBe('forbidden_source');
    // Keyword match has lower confidence than prefix match
    expect($memory->confidence_score)->toBe('0.7400');
});
