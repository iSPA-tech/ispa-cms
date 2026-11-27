<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\StepUpException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowChangeRefused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowPublishRefused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowStepUpRequired;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionDiff;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Illuminate\Http\Request;

/**
 * Workflow versions (guardrails B1/B3): history, diff, publish, rollback, draft status, and change control (preview of
 * a publish, waiting versions, approve / reject / cancel / release now).
 *
 * Projects are addressed by numeric id like the builder routes (`workflow_projects` has no uuid); versions by uuid.
 * Read: policy `view`. Publish, rollback and decisions: policy `publish`. A sensitive change needs a step-up grant of
 * purpose `workflow_publish` in the `X-Step-Up-Grant` header (the transport of the `security.stepup` middleware);
 * without one the answer is 409 `step_up_required` with `data.purpose`. Responses carry no seal.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026-11-24
 */
class WorkflowVersionController extends Controller
{
    public function __construct(protected WorkflowPublishService $publisher)
    {
    }

    /** GET versions: newest first. */
    public function index(Request $request, string $project): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::VIEW_ABILITY)) {
            return $denied;
        }

        $versions = WorkflowVersion::query()
            ->where('project_id', $model->getKey())
            ->with('creator:id,name')
            ->orderByDesc('number')
            ->limit(200)
            ->get()
            ->map(fn (WorkflowVersion $version) => $this->summary($version, $model))
            ->all();

        return ires()->data(['versions' => $versions, 'draft' => $this->publisher->draftStatus($model)]);
    }

    /** GET versions/{uuid}: summary + graph. */
    public function show(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::VIEW_ABILITY)) {
            return $denied;
        }
        $version = $this->version($model, $uuid);

        return ires()->data($this->summary($version, $model) + ['graph' => $version->graphData()]);
    }

    /** GET versions/{uuid}/diff?against={uuid|draft} (default: draft). */
    public function diff(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::VIEW_ABILITY)) {
            return $denied;
        }
        $version = $this->version($model, $uuid);

        $against = (string) $request->query('against', 'draft');
        $other = $against === 'draft'
            ? WorkflowVersionSnapshot::fromDraft($model)
            : $this->version($model, $against)->graphData();

        return ires()->data([
            'version' => $version->uuid,
            'against' => $against,
            'diff' => WorkflowVersionDiff::between($version->graphData(), $other),
        ]);
    }

    /** GET versions/draft-status: does the draft differ from the published version? */
    public function draftStatus(Request $request, string $project): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::VIEW_ABILITY)) {
            return $denied;
        }

        return ires()->data($this->publisher->draftStatus($model));
    }

    /**
     * POST versions/publish {note, release_now}: publish the draft (422 with `problems` when the policy refuses it).
     * `release_now` skips the cooling-off of a single-publisher site; a second publisher still decides.
     */
    public function publish(Request $request, string $project): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::PUBLISH_ABILITY)) {
            return $denied;
        }

        $note = $request->input('note');
        try {
            $version = $this->publisherFor($request)->publish($model, $request->user(), is_string($note) ? $note : null, $request->boolean('release_now'));
        } catch (WorkflowPublishRefused $e) {
            return $this->refused($e);
        } catch (WorkflowStepUpRequired $e) {
            return $this->stepUpRequired($e);
        } catch (StepUpException $e) {
            return $e->render();
        }

        return $this->published($version, $model->refresh());
    }

    /** GET versions/preview-publish: what publishing the draft now would do (path, sensitive changes, step-up). */
    public function previewPublish(Request $request, string $project): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->denyUnlessAny($request, $model, [WorkflowPublishService::VIEW_ABILITY, WorkflowPublishService::PUBLISH_ABILITY])) {
            return $denied;
        }

        return ires()->data($this->publisherFor($request)->preview($model, $request->user()));
    }

    /** GET workflow/versions/pending: waiting versions of every project the caller may publish. */
    public function pending(Request $request): AppResponse
    {
        $user = $request->user();
        $rows = [];
        $versions = WorkflowVersion::query()
            ->whereIn('status', WorkflowPublishService::WAITING_STATUSES)
            ->with(['project', 'creator:id,name'])
            ->orderBy('id')
            ->limit(200)
            ->get();
        foreach ($versions as $version) {
            $project = $version->project;
            if (!$project || !$user?->can(WorkflowPublishService::PUBLISH_ABILITY, $project)) {
                continue;
            }
            $rows[] = [
                'uuid' => $version->uuid,
                'project_id' => (int) $project->getKey(),
                'project_uuid' => $project->uuid,
                'project_name' => $project->name,
                'number' => $version->number,
                'status' => $version->status,
                'created_by_name' => $version->creator?->name,
                'created_at' => $version->created_at?->toISOString(),
                'effective_at' => $version->effective_at?->toISOString(),
                'changes' => $this->publisher->changesOf($version),
                'can_approve' => $this->publisher->canApprove($version, $user, $project),
                'can_cancel' => $this->publisher->canCancel($version, $user),
                'can_release_now' => $this->publisher->canReleaseNow($version, $user, $project),
            ];
        }

        return ires()->data($rows);
    }

    /** POST versions/{uuid}/approve: another publisher puts a waiting change live (step-up; never the author). */
    public function approve(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::PUBLISH_ABILITY)) {
            return $denied;
        }
        $version = $this->version($model, $uuid);

        try {
            $live = $this->publisherFor($request)->approve($version, $request->user());
        } catch (WorkflowChangeRefused $e) {
            return $this->changeRefused($e);
        } catch (WorkflowStepUpRequired $e) {
            return $this->stepUpRequired($e);
        } catch (StepUpException $e) {
            return $e->render();
        } catch (WorkflowPublishRefused $e) {
            return $this->refused($e);
        } catch (WorkflowIntegrityException $e) {
            return ires()->error()->code(422)->errorCode($e::CODE)
                ->message('This version was changed while it waited; it can not go live.');
        }

        return ires()->data($this->summary($live, $model->refresh()) + ['path' => WorkflowPublishService::PATH_PUBLISHED]);
    }

    /** POST versions/{uuid}/release-now: a single publisher ends the cooling-off of a waiting change (step-up). */
    public function releaseNow(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::PUBLISH_ABILITY)) {
            return $denied;
        }
        $version = $this->version($model, $uuid);

        try {
            $live = $this->publisherFor($request)->releaseNow($version, $request->user());
        } catch (WorkflowChangeRefused $e) {
            return $this->changeRefused($e);
        } catch (WorkflowStepUpRequired $e) {
            return $this->stepUpRequired($e);
        } catch (StepUpException $e) {
            return $e->render();
        } catch (WorkflowPublishRefused $e) {
            return $this->refused($e);
        } catch (WorkflowIntegrityException $e) {
            return ires()->error()->code(422)->errorCode($e::CODE)
                ->message('This version was changed while it waited; it can not go live.');
        }

        return ires()->data($this->summary($live, $model->refresh()) + ['path' => WorkflowPublishService::PATH_PUBLISHED]);
    }

    /** POST versions/{uuid}/reject {reason?}: another publisher refuses a waiting change. */
    public function reject(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::PUBLISH_ABILITY)) {
            return $denied;
        }
        $version = $this->version($model, $uuid);
        $reason = $request->input('reason');

        try {
            $version = $this->publisher->reject($version, $request->user(), is_string($reason) ? $reason : null);
        } catch (WorkflowChangeRefused $e) {
            return $this->changeRefused($e);
        }

        return ires()->data($this->summary($version, $model->refresh()));
    }

    /** POST versions/{uuid}/cancel: the author or a Webmaster withdraws a waiting change. */
    public function cancel(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->denyUnlessAny($request, $model, [WorkflowPublishService::VIEW_ABILITY, WorkflowPublishService::PUBLISH_ABILITY])) {
            return $denied;
        }
        $version = $this->version($model, $uuid);

        try {
            $version = $this->publisher->cancel($version, $request->user());
        } catch (WorkflowChangeRefused $e) {
            return $this->changeRefused($e);
        }

        return ires()->data($this->summary($version, $model->refresh()));
    }

    /** POST versions/{uuid}/rollback: publish a new version equal to {uuid}. */
    public function rollback(Request $request, string $project, string $uuid): AppResponse
    {
        $model = $this->project($project);
        if ($denied = $this->deny($request, $model, WorkflowPublishService::PUBLISH_ABILITY)) {
            return $denied;
        }
        $target = $this->version($model, $uuid);

        try {
            $version = $this->publisherFor($request)->rollback($model, $target, $request->user());
        } catch (WorkflowPublishRefused $e) {
            return $this->refused($e);
        } catch (WorkflowStepUpRequired $e) {
            return $this->stepUpRequired($e);
        } catch (StepUpException $e) {
            return $e->render();
        } catch (WorkflowIntegrityException $e) {
            return ires()->error()->code(422)->errorCode($e::CODE)
                ->message('That version failed its integrity check; it can not be restored.');
        }

        return $this->published($version, $model->refresh());
    }

    /**
     * Publish/rollback answer: 201 published, 200 unchanged, 202 waiting (with effective_at and the changes).
     */
    protected function published(WorkflowVersion $version, WorkflowProject $project): AppResponse
    {
        $path = $version->publishPath ?? WorkflowPublishService::PATH_PUBLISHED;
        $data = $this->summary($version, $project) + ['path' => $path];

        return match ($path) {
            WorkflowPublishService::PATH_PUBLISHED => ires()->code(201)->data($data),
            WorkflowPublishService::PATH_UNCHANGED => ires()->code(200)->data($data),
            default => ires()->code(202)->data($data + ['changes' => $version->publishChanges]),
        };
    }

    protected function publisherFor(Request $request): WorkflowPublishService
    {
        $grant = $request->header(StepUpConfirmation::HEADER);

        return $this->publisher->withStepUpGrant(is_string($grant) ? $grant : null);
    }

    protected function stepUpRequired(WorkflowStepUpRequired $e): AppResponse
    {
        return ires()->error()->code(409)->errorCode($e::CODE)
            ->data(['purpose' => $e->purpose])
            ->message($e->getMessage());
    }

    protected function changeRefused(WorkflowChangeRefused $e): AppResponse
    {
        return ires()->error()->code($e->status)->errorCode($e->errorCode)->message($e->getMessage());
    }

    protected function project(string $uuid): WorkflowProject
    {
        return WorkflowProject::query()->where('uuid', $uuid)->firstOrFail();
    }

    protected function version(WorkflowProject $project, string $uuid): WorkflowVersion
    {
        return WorkflowVersion::query()
            ->where('project_id', $project->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    protected function deny(Request $request, WorkflowProject $project, string $ability): ?AppResponse
    {
        return $request->user()?->can($ability, $project) ? null : ires()->forbidden(__('mgs.no.permission'));
    }

    /**
     * @param list<string> $abilities
     */
    protected function denyUnlessAny(Request $request, WorkflowProject $project, array $abilities): ?AppResponse
    {
        foreach ($abilities as $ability) {
            if ($request->user()?->can($ability, $project)) {
                return null;
            }
        }

        return ires()->forbidden(__('mgs.no.permission'));
    }

    protected function refused(WorkflowPublishRefused $e): AppResponse
    {
        return ires()->error()->code(422)->errorCode($e::CODE)
            ->data(['problems' => $e->problems])
            ->message($e->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(WorkflowVersion $version, WorkflowProject $project): array
    {
        $graph = $version->graphData();

        return [
            'uuid' => $version->uuid,
            'number' => $version->number,
            'status' => $version->status,
            'is_published' => (int) $project->published_version_id === (int) $version->id,
            'content_hash' => $version->content_hash,
            'note' => $version->note,
            'steps' => count((array) ($graph['steps'] ?? [])),
            'created_by' => $version->created_by,
            'created_by_name' => $version->creator?->name,
            'approved_by' => $version->approved_by,
            'approved_at' => $version->approved_at?->toISOString(),
            'effective_at' => $version->effective_at?->toISOString(),
            'created_at' => $version->created_at?->toISOString(),
        ];
    }
}
