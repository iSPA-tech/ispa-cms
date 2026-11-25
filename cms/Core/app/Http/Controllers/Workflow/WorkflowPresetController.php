<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog;
use Illuminate\Http\Request;

/**
 * Ready-made choices of the workflow step form: `GET api/v2/admin/workflows/presets?kind=<kind>`.
 *
 * Needs `workflow_project.create` or `workflow_project.update`. `kind` is one of WorkflowPresetCatalog::kinds()
 * (`jev_criteria`, `score_rubric`, `classify_labels`); returns `[{key, label, description, module, items, source?}]`
 * with text in the user's language.
 *
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-24
 */
class WorkflowPresetController extends Controller
{
    public function index(Request $request, WorkflowPresetCatalog $catalog)
    {
        $user = user();
        if (!$user?->can('workflow_project.update') && !$user?->can('workflow_project.create')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $kind = (string) $request->query('kind', '');
        if (!in_array($kind, WorkflowPresetCatalog::kinds(), true)) {
            return ires()->error()->code(422)->errorCode('validation_failed')
                ->message('kind must be one of: ' . implode(', ', WorkflowPresetCatalog::kinds()) . '.');
        }

        return ires()->data($catalog->forKind($kind));
    }
}
