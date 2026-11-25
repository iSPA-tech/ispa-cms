<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow change control, review fixes 14 and 15 (2026-11-25). Additive.
 *
 * - workflow_projects.draft_editor_ids (json): users who changed the draft's steps or connections since the draft last
 *   matched the live version (WorkflowDraftEditors). Nobody in it may approve the next version.
 * - workflow_versions.editor_ids (json): the draft editors frozen into a version when it was published.
 * - workflow_projects.published_version_seal (char 64): HMAC of "<project_id>|<published_version_id>"
 *   (WorkflowVersionSeal::signPointer()). New runs refuse a pointer whose seal does not match.
 *
 * Backfill: every project that points at a version gets its pointer sealed with the current APP_KEY. A site without
 * APP_KEY is left unsealed (its runs are refused until `cms:workflow-reseal --force`, like every seal).
 */
return new class extends Migration
{
    private const VERSIONS = 'workflow_versions';

    public function up(): void
    {
        if (Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'draft_editor_ids')) {
                    $table->json('draft_editor_ids')->nullable();
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'published_version_seal')) {
                    $table->char('published_version_seal', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable(self::VERSIONS) && !Schema::hasColumn(self::VERSIONS, 'editor_ids')) {
            Schema::table(self::VERSIONS, function (Blueprint $table) {
                $table->json('editor_ids')->nullable();
            });
        }

        $this->sealPointers();
    }

    public function down(): void
    {
        if (Schema::hasTable(self::VERSIONS) && Schema::hasColumn(self::VERSIONS, 'editor_ids')) {
            Schema::table(self::VERSIONS, fn (Blueprint $table) => $table->dropColumn('editor_ids'));
        }

        if (Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            $present = array_values(array_filter(
                ['draft_editor_ids', 'published_version_seal'],
                static fn (string $column): bool => Schema::hasColumn(Tables::WORKFLOW_PROJECT, $column),
            ));
            if ($present !== []) {
                Schema::table(Tables::WORKFLOW_PROJECT, fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
    }

    private function sealPointers(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT)
            || !Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'published_version_id')
            || !Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'published_version_seal')
            || (string) config('app.key') === '') {
            return;
        }

        DB::table(Tables::WORKFLOW_PROJECT)
            ->whereNotNull('published_version_id')
            ->orderBy('id')
            ->select(['id', 'published_version_id'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table(Tables::WORKFLOW_PROJECT)->where('id', $row->id)->update([
                        'published_version_seal' => WorkflowVersionSeal::signPointer((int) $row->id, (int) $row->published_version_id),
                    ]);
                }
            });
    }
};
