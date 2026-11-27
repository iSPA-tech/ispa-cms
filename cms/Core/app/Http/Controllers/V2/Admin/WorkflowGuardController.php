<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Cms\Core\Http\Controllers\Workflow\WorkflowReviewerController;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeReviewers;
use InvalidArgumentException;
use Illuminate\Http\Request;

/**
 * Workflow guardrail switches (roadmap B7).
 *
 * - `GET  workflow/pause-all` → {paused, forced_by_server, setting}; `PUT workflow/pause-all` with `{paused: bool}`
 *   turns the Webmaster's switch on or off (audited, alerted). Both are Webmaster only (route middleware).
 * - `POST workflow/projects/{project}/resume` switches a project paused by a circuit breaker back on. Needs the
 *   project `publish` ability (it changes what runs on the live site) and, once versions exist, a published version.
 *   A project paused for another reason (integrity, a person) answers 422 `not_paused_by_breaker`.
 *
 * Workflow projects have no `uuid` column, so they are addressed by id like the rest of the workflow admin API.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026-11-24
 */
class WorkflowGuardController extends Controller
{
    public function showPauseAll(WorkflowGlobalPause $pause): AppResponse
    {
        return ires()->data($this->pausePayload($pause));
    }

    public function updatePauseAll(Request $request, WorkflowGlobalPause $pause): AppResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $user = $request->user();

        $pause->set((bool) $data['paused'], $user instanceof User ? $user : null, 'admin');

        return ires()->data($this->pausePayload($pause));
    }

    public function resume(Request $request, string $project, WorkflowCircuitBreaker $breaker, WorkflowPublishService $publisher): AppResponse
    {
        $model = WorkflowProject::query()->where('uuid', $project)->first();
        if (! $model) {
            return ires()->notFound(__('mgs.model.404'));
        }
        if (! $request->user()?->can('publish', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }
        try {
            $publisher->assertCanActivate($model);
        } catch (WorkflowProjectNotPublished $e) {
            return ires()->error()->code(422)->errorCode(WorkflowPublishService::NOT_PUBLISHED_CODE)->message($e->getMessage());
        }

        $user = $request->user();
        if (! $breaker->resume($model, $user instanceof User ? $user : null)) {
            return ires()->error()->code(422)->errorCode('not_paused_by_breaker')
                ->message('This workflow was not paused by a safety limit.');
        }

        return ires()->data([
            'is_active' => (bool) $model->is_active,
            'paused_reason' => $model->getAttribute('paused_reason'),
        ]);
    }

    /**
     * @return array{paused: bool, forced_by_server: bool, setting: bool}
     */
    /**
     * Who reviews workflow changes: `{ named: [{uuid, name, detail}], default: bool }` (`default`: nobody is named, so
     * every publisher and Webmaster reviews).
     */
    public function showReviewers(WorkflowChangeReviewers $reviewers): AppResponse
    {
        return ires()->data($this->reviewersPayload($reviewers));
    }

    public function updateReviewers(Request $request, WorkflowChangeReviewers $reviewers): AppResponse
    {
        $data = $request->validate(['users' => ['present', 'array', 'max:' . WorkflowChangeReviewers::MAX], 'users.*' => ['string', 'max:64']]);
        $user = $request->user();

        try {
            $reviewers->set(array_values($data['users']), $user instanceof User ? $user : null);
        } catch (InvalidArgumentException $e) {
            return ires()->error()->code(422)->errorCode('validation_failed')->message($e->getMessage());
        }

        return ires()->data($this->reviewersPayload($reviewers));
    }

    private function reviewersPayload(WorkflowChangeReviewers $reviewers): array
    {
        $named = array_map(static fn (User $user): array => [
            'uuid' => (string) $user->uuid,
            'name' => (string) $user->name,
            'detail' => WorkflowReviewerController::detail($user),
        ], $reviewers->users());

        return ['named' => $named, 'default' => $named === []];
    }

    private function pausePayload(WorkflowGlobalPause $pause): array
    {
        return [
            'paused' => $pause->active(),
            'forced_by_server' => $pause->forcedByServer(),
            'setting' => $pause->settingOn(),
        ];
    }
}
