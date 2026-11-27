<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval;

/**
 * Auto-publish state of one approval step: `GET api/v2/admin/workflows/{project}/steps/{step_code}/auto-approval`
 * (project `view` ability; the project key of the builder routes, workflow projects have no uuid).
 *
 * → `{data: {available, mode, min_confidence, source, decided, required, agreed, disagreed, agreement_rate,
 * minimum_agreement, approved_after_edit, excluded, ready, paused, estimate: {"0.6": n, "0.8": n, "0.9": n, of: n},
 * last_30_days: {auto, human, withdrawn}}}`. Read from the draft step (the designer edits the draft).
 *
 * @see WorkflowAutoApproval::describe()
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-24
 */
class WorkflowAutoApprovalController extends Controller
{
    public function show(string $project, string $stepCode, WorkflowAutoApproval $autoApproval)
    {
        $model = WorkflowProject::query()->where('uuid', $project)->first();
        if (!$model) {
            return ires()->notFound(__('mgs.model.404'));
        }
        if (!user()?->can('view', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $data = $autoApproval->describe($model, $stepCode);
        if ($data === null) {
            return ires()->notFound(__('mgs.model.404'));
        }

        return ires()->data($data);
    }
}
