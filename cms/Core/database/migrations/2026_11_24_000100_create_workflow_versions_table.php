<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Workflow guardrails B1/B2: published, sealed versions of workflow projects.
 *
 * - workflow_versions: immutable canonical graph snapshots (content hash + HMAC seal).
 * - workflow_projects: published_version_id, paused_reason / paused_at (integrity check, breakers),
 *   policy_exemptions (publish steps a Webmaster exempted from the approval rule).
 * - workflow_instances: version_id (the version a run executes, set when it is created).
 *
 * Backfill (plain database reads, no node registry, no queue): every existing project gets version 1 built from its
 * current rows with WorkflowVersionSnapshot::fromDatabase() (the same function the publish service uses, so the JSON is
 * byte-identical), sealed, published and attached; every instance of it that has not finished gets version_id = v1.
 * Foreign keys are added on MariaDB/MySQL/PostgreSQL only (SQLite cannot add them to an existing table).
 */
return new class extends Migration
{
    private const VERSIONS = 'workflow_versions';

    /** Instance statuses after which a run never executes another step. */
    private const TERMINAL = ['completed', 'failed', 'cancelled', 'rejected', 'approval_rejected', 'budget_exceeded', 'success'];

    public function up(): void
    {
        if (!Schema::hasTable(self::VERSIONS)) {
            Schema::create(self::VERSIONS, function (Blueprint $table) {
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

                $table->unique(['project_id', 'number']);
                $table->index(['project_id', 'status']);
                $table->foreign('project_id')->references('id')->on(Tables::WORKFLOW_PROJECT)->cascadeOnDelete();
            });
        }

        $foreignKeys = DB::getDriverName() !== 'sqlite';

        if (Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) use ($foreignKeys) {
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'published_version_id')) {
                    $table->unsignedBigInteger('published_version_id')->nullable();
                    if ($foreignKeys) {
                        $table->foreign('published_version_id')->references('id')->on(self::VERSIONS)->nullOnDelete();
                    } else {
                        $table->index('published_version_id');
                    }
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'paused_reason')) {
                    $table->string('paused_reason', 40)->nullable();
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'paused_at')) {
                    $table->timestamp('paused_at')->nullable();
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'policy_exemptions')) {
                    $table->json('policy_exemptions')->nullable();
                }
            });
        }

        if (Schema::hasTable(Tables::WORKFLOW_INSTANCE) && !Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'version_id')) {
            Schema::table(Tables::WORKFLOW_INSTANCE, function (Blueprint $table) use ($foreignKeys) {
                $table->unsignedBigInteger('version_id')->nullable();
                if ($foreignKeys) {
                    $table->foreign('version_id')->references('id')->on(self::VERSIONS)->nullOnDelete();
                } else {
                    $table->index('version_id');
                }
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        $foreignKeys = DB::getDriverName() !== 'sqlite';

        if (Schema::hasTable(Tables::WORKFLOW_INSTANCE) && Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'version_id')) {
            Schema::table(Tables::WORKFLOW_INSTANCE, function (Blueprint $table) use ($foreignKeys) {
                $foreignKeys ? $table->dropForeign(['version_id']) : $table->dropIndex(['version_id']);
                $table->dropColumn('version_id');
            });
        }

        if (Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            if (Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'published_version_id')) {
                Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) use ($foreignKeys) {
                    $foreignKeys ? $table->dropForeign(['published_version_id']) : $table->dropIndex(['published_version_id']);
                    $table->dropColumn('published_version_id');
                });
            }
            $present = array_values(array_filter(
                ['paused_reason', 'paused_at', 'policy_exemptions'],
                fn (string $column) => Schema::hasColumn(Tables::WORKFLOW_PROJECT, $column)
            ));
            if ($present !== []) {
                Schema::table(Tables::WORKFLOW_PROJECT, fn (Blueprint $table) => $table->dropColumn($present));
            }
        }

        Schema::dropIfExists(self::VERSIONS);
    }

    /**
     * Version 1 for every project that has none yet, from its current rows.
     */
    private function backfill(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT) || !Schema::hasTable(Tables::WORKFLOW_STEP)
            || !Schema::hasTable(Tables::WORKFLOW_CONNECTION)) {
            return;
        }

        $projectIds = DB::table(Tables::WORKFLOW_PROJECT)
            ->whereNull('deleted_at')
            ->whereNull('published_version_id')
            ->orderBy('id')
            ->pluck('id');

        foreach ($projectIds as $projectId) {
            $projectId = (int) $projectId;
            DB::transaction(function () use ($projectId): void {
                $graph = WorkflowVersionSnapshot::fromDatabase($projectId);
                $json = WorkflowVersionSnapshot::encode($graph);
                $hash = WorkflowVersionSnapshot::hashText($json);
                $now = now();

                $versionId = DB::table(self::VERSIONS)->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'project_id' => $projectId,
                    'number' => 1,
                    'graph' => $json,
                    'content_hash' => $hash,
                    'seal' => WorkflowVersionSeal::sign($projectId, 1, $hash),
                    'status' => 'published',
                    'note' => 'Version 1 recorded when versions were introduced.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table(Tables::WORKFLOW_PROJECT)->where('id', $projectId)->update(['published_version_id' => $versionId]);

                if (Schema::hasTable(Tables::WORKFLOW_INSTANCE)) {
                    DB::table(Tables::WORKFLOW_INSTANCE)
                        ->where('project_id', $projectId)
                        ->whereNull('version_id')
                        ->whereNotIn('status', self::TERMINAL)
                        ->update(['version_id' => $versionId]);
                }
            });
        }
    }
};
