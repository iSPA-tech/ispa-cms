<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Http\Request;

/**
 * Setup screen of an installed sample workflow: `GET|PUT api/v2/admin/workflows/{project}/setup` (the project key of
 * the builder routes; workflow projects have no uuid).
 *
 * GET (project `view` ability) → `{complete, questions: [{key, kind, step_code?, field?, label, help, required,
 * answered, current, assignees_allowed?}], preset: [{label}]}`; `assignees_allowed` (reviewers only) says whether the
 * step takes named people (only an approval step does).
 * PUT (project `update` ability) `{answers: {<key>: value}}` writes the draft after the checks of a designer save and
 * sets the schedule through the schedule rule; 422 `{errors: [...]}` refuses the whole request; success returns the GET
 * shape. A system-managed project cannot be changed here.
 * POST `test-run` (project `update` ability) walks the draft through the package dry run with the package sample (or
 * an empty one): side effects are stubbed, nothing is sent, published or spent. Returns the package preview report
 * (`dry_run`, `problems`, ...); 422 `setup_incomplete` while a question is unanswered and the draft cannot be checked yet. Unlike `workflow/packages/preview` it needs no package-install right, so an editor who
 * answers the setup questions can also check them.
 *
 * @see WorkflowSetupService
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-24
 */
class WorkflowSetupController extends Controller
{
    public function show(string $project, WorkflowSetupService $setup)
    {
        $model = WorkflowProject::query()->find($project);
        if (!$model) {
            return ires()->notFound(__('mgs.model.404'));
        }
        if (!user()?->can('view', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return ires()->data($setup->describe($model));
    }

    public function update(Request $request, string $project, WorkflowSetupService $setup, WorkflowProjectRunService $runs)
    {
        $model = WorkflowProject::query()->find($project);
        if (!$model) {
            return ires()->notFound(__('mgs.model.404'));
        }
        if (!$request->user()?->can('update', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }
        if ($runs->isSystemManaged($model)) {
            return ires()->error()->code(422)->message('System-managed workflow wrappers can not be modified here.');
        }

        $answers = $request->input('answers');
        if (!is_array($answers) || ($answers !== [] && array_is_list($answers))) {
            return ires()->error()->code(422)->errorCode('validation_failed')->message('answers must be an object of question key => value.');
        }

        $problems = $setup->apply($model, $answers, $request->user());
        if ($problems !== []) {
            return ires()->error()->code(422)->data(['errors' => $problems])
                ->message(implode('; ', array_column($problems, 'message')));
        }

        return ires()->data($setup->describe($model->fresh()));
    }

    public function testRun(Request $request, string $project, WorkflowPackageExporter $exporter, WorkflowPackageInspector $inspector, WorkflowSetupService $setup)
    {
        $model = WorkflowProject::query()->find($project);
        if (!$model) {
            return ires()->notFound(__('mgs.model.404'));
        }
        if (!$request->user()?->can('update', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        try {
            $package = $exporter->export((string) $model->slug);
        } catch (WorkflowPackageException $e) {
            // An unanswered account field (an empty `credential`) keeps the draft from being exported: ask for the answers first.
            return ires()->error()->code(422)->errorCode($setup->describe($model)['complete'] ? 'test_run_unavailable' : 'setup_incomplete')
                ->message($e->getMessage());
        }

        // Checked as a copy (the workflow itself already uses its slug); a package without a sample runs on an empty one.
        $sample = is_array($package['sample'] ?? null) ? null : [];

        return ires()->data($inspector->inspect($package, $request->user(), [], $model->slug . '-test-run', $sample));
    }
}
