<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Support;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test helper: publish a workflow project built directly in a test, so runs can start (runs only execute published,
 * sealed versions; the fail-closed rule is never relaxed for tests).
 *
 * publish() freezes the project's current draft rows into a new sealed version (no designer policy: tests of the
 * runtime build graphs the policy is not about) and returns it. attach() puts an instance created by hand on the
 * project's published version. Tests that build their own minimal workflow tables get the version table and columns
 * added first (ensureSchema()).
 */
final class WorkflowTestVersions
{
    public static function publish(WorkflowProject $project): WorkflowVersion
    {
        self::ensureSchema();

        return app(WorkflowPublishService::class)->publishSystem($project, 'Published by a test.');
    }

    /**
     * Put a hand-made instance on a version of its project's current draft (published first when it changed).
     */
    public static function attach(WorkflowInstance $instance): WorkflowInstance
    {
        self::ensureSchema();
        $project = WorkflowProject::query()->findOrFail($instance->project_id);
        // The current draft (unchanged drafts reuse the published version), like the old draft-reading runtime.
        $versionId = self::publish($project)->id;
        $instance->forceFill(['version_id' => $versionId])->save();

        return $instance;
    }

    /**
     * Create an instance (like WorkflowInstance::create()) on its project's published version.
     *
     * @param array<string, mixed> $attributes
     */
    public static function instance(array $attributes): WorkflowInstance
    {
        self::ensureSchema();
        $instance = WorkflowInstance::query()->create($attributes);

        return empty($attributes['project_id']) || array_key_exists('version_id', $attributes) ? $instance : self::attach($instance);
    }

    /**
     * Add the version table and columns to a hand-built test schema (no-op on a migrated database).
     */
    public static function ensureSchema(): void
    {
        if (!Schema::hasTable('workflow_versions')) {
            Schema::create('workflow_versions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('project_id');
                $table->unsignedInteger('number');
                $table->longText('graph');
                $table->char('content_hash', 64);
                $table->char('sensitive_fingerprint', 64)->nullable();
                $table->json('sensitive_summary')->nullable();
                $table->char('seal', 64);
                $table->string('status', 20)->default('published');
                $table->timestamp('effective_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('note', 1000)->nullable();
                $table->timestamps();
            });
        }

        foreach ([
            'workflow_projects' => ['published_version_id' => 'id', 'paused_reason' => 'string', 'paused_at' => 'timestamp', 'policy_exemptions' => 'json', 'draft_editor_ids' => 'json', 'published_version_seal' => 'seal'],
            'workflow_instances' => ['version_id' => 'id'],
            'workflow_versions' => ['editor_ids' => 'json'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($columns as $column => $type) {
                if (Schema::hasColumn($tableName, $column)) {
                    continue;
                }
                Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
                    match ($type) {
                        'id' => $table->unsignedBigInteger($column)->nullable(),
                        'string' => $table->string($column, 40)->nullable(),
                        'timestamp' => $table->timestamp($column)->nullable(),
                        'seal' => $table->char($column, 64)->nullable(),
                        default => $table->json($column)->nullable(),
                    };
                });
            }
        }
    }
}
