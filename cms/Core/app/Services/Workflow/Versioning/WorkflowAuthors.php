<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote a workflow's behaviour (review fixes 6, 7 and 14, 2026-11-25).
 *
 * - Draft editors: every signed-in user who created, changed, deleted or restored a step or connection of the project
 *   (WorkflowScheduleOwnershipObserver calls recordDraftEdit()), kept in `workflow_projects.draft_editor_ids` until a
 *   version goes live whose graph equals the draft (the list then describes nothing unpublished). A designer save
 *   recreates every connection, so pressing Save without a change also counts: the list errs on the side of more names.
 *   Changes made without a signed-in user (console installs, system-managed graphs) name nobody.
 * - Version authors: who pressed Publish (`created_by`) plus the draft editors frozen into the version at publish
 *   (`workflow_versions.editor_ids`).
 * - Project authors: the authors of every version of the project plus the current draft editors.
 *
 * Used by: version approval (no author approves a version they wrote), run approvals (no author of the version a run
 * executes decides its approval items) and JEV calibration (no author's decisions count).
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-25
 */
final class WorkflowAuthors
{
    /** Whether the editor columns exist (asked once per process; `once()` is flushed between tests). */
    public static function tracked(): bool
    {
        return once(static fn (): bool => Schema::hasColumn((new WorkflowProject())->getTable(), 'draft_editor_ids')
            && Schema::hasColumn((new WorkflowVersion())->getTable(), 'editor_ids'));
    }

    /**
     * Add $userId to the project's draft editors (under the project row lock; no model events).
     */
    public static function recordDraftEdit(int $projectId, ?int $userId): void
    {
        if ($projectId <= 0 || $userId === null || $userId <= 0 || !self::tracked()) {
            return;
        }

        DB::transaction(static function () use ($projectId, $userId): void {
            $table = (new WorkflowProject())->getTable();
            $row = DB::table($table)->where('id', $projectId)->lockForUpdate()->first(['draft_editor_ids']);
            if ($row === null) {
                return;
            }
            $ids = self::ids($row->draft_editor_ids);
            if (in_array($userId, $ids, true)) {
                return;
            }
            $ids[] = $userId;
            DB::table($table)->where('id', $projectId)->update(['draft_editor_ids' => json_encode($ids)]);
        });
    }

    /**
     * @return list<int>
     */
    public static function draftEditors(WorkflowProject|int $project): array
    {
        if (!self::tracked()) {
            return [];
        }
        $id = $project instanceof WorkflowProject ? (int) $project->getKey() : $project;

        return self::ids(DB::table((new WorkflowProject())->getTable())->where('id', $id)->value('draft_editor_ids'));
    }

    /**
     * Who wrote a version: its publisher and the draft editors frozen into it.
     *
     * @return list<int>
     */
    public static function ofVersion(?WorkflowVersion $version): array
    {
        if ($version === null) {
            return [];
        }
        $ids = self::tracked() ? self::ids($version->getAttribute('editor_ids')) : [];
        if (is_numeric($version->created_by) && (int) $version->created_by > 0) {
            $ids[] = (int) $version->created_by;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Who wrote any version of the project, plus the current draft editors.
     *
     * @return list<int>
     */
    public static function ofProject(WorkflowProject|int $project): array
    {
        $id = $project instanceof WorkflowProject ? (int) $project->getKey() : $project;
        $columns = self::tracked() ? ['created_by', 'editor_ids'] : ['created_by'];
        $ids = self::draftEditors($id);
        if (!self::tracked() && !once(static fn (): bool => Schema::hasTable((new WorkflowVersion())->getTable()))) {
            return $ids; // a schema without versions (never published) has no publishers
        }
        foreach (WorkflowVersion::query()->where('project_id', $id)->get($columns) as $version) {
            $ids = [...$ids, ...self::ofVersion($version)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Once a version goes live whose graph equals the draft, the draft holds nothing unpublished: forget its editors.
     * A draft that still differs keeps them (they may have edits not in the live version).
     */
    public static function settleDraft(WorkflowProject $locked, WorkflowVersion $live): void
    {
        if (!self::tracked()) {
            return;
        }
        $draftHash = WorkflowVersionSnapshot::hash(WorkflowVersionSnapshot::fromDraft($locked));
        if (!hash_equals((string) $live->content_hash, $draftHash)) {
            return;
        }

        DB::table($locked->getTable())->where('id', $locked->getKey())->update(['draft_editor_ids' => null]);
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($raw, static fn ($id): bool => is_numeric($id) && (int) $id > 0))));
    }
}
