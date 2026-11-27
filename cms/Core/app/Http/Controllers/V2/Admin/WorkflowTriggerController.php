<?php

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Cms\Core\Services\Workflow\Triggers\WebhookTriggerService;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Illuminate\Http\Request;

/**
 * Project-level trigger settings: the webhook URL/secret and the error workflow.
 *
 * Workflow projects have no `uuid` column, so they are addressed by id like the rest of the workflow admin API.
 * Every action needs the project `update` ability. The webhook secret is returned once, when it is issued.
 * Rotate, disable and error-workflow changes are audited (roadmap B5), never with the token or secret.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026.11 (2026-11-24)
 */
class WorkflowTriggerController extends Controller
{
    public function webhook(Request $request, string $project): AppResponse
    {
        $model = $this->authorizedProject($request, $project);
        if ($model instanceof AppResponse) {
            return $model;
        }

        return ires()->data(['webhook' => $this->serializeWebhook($model)]);
    }

    /**
     * Issue a new URL token and secret; the previous ones stop working.
     */
    public function rotateWebhook(Request $request, string $project, WebhookTriggerService $webhooks): AppResponse
    {
        $model = $this->authorizedProject($request, $project);
        if ($model instanceof AppResponse) {
            return $model;
        }

        // A webhook secret lets anyone outside start the published workflow: issuing one is a publisher's call behind a
        // step-up (route middleware security.stepup:workflow_publish), and a live workflow alerts (guardrails B3/B6).
        if (! $request->user()?->can('publish', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $hadWebhook = $model->webhook_token !== null;
        $issued = $webhooks->rotate($model);
        WorkflowAudit::project(WorkflowAudit::WEBHOOK_ROTATED, $model, ['replaced_previous' => $hadWebhook], 'warning', $this->actorId($request));
        if ($model->is_active) {
            app(WorkflowChangeAlerter::class)->notify('credential_changed', $model, ['credential' => 'webhook'], $request->user());
        }

        return ires()->data(['webhook' => $this->serializeWebhook($model) + ['secret' => $issued['secret']]]);
    }

    public function disableWebhook(Request $request, string $project, WebhookTriggerService $webhooks): AppResponse
    {
        $model = $this->authorizedProject($request, $project);
        if ($model instanceof AppResponse) {
            return $model;
        }

        $hadWebhook = $model->webhook_token !== null;
        $webhooks->disable($model);
        if ($hadWebhook) {
            WorkflowAudit::project(WorkflowAudit::WEBHOOK_DISABLED, $model, ['enabled' => ['from' => true, 'to' => false]], 'warning', $this->actorId($request));
        }

        return ires()->data(['webhook' => $this->serializeWebhook($model)]);
    }

    /**
     * Body: {error_workflow_project_id: int|null}. The error workflow must be another project whose start node is
     * `trigger_error`, and the caller must be allowed to update it too.
     */
    public function errorWorkflow(Request $request, string $project): AppResponse
    {
        $model = $this->authorizedProject($request, $project);
        if ($model instanceof AppResponse) {
            return $model;
        }

        // The error workflow is named by its uuid (UUID-first); empty turns it off.
        $targetUuid = $request->input('error_workflow_uuid');
        $target = null;
        if ($targetUuid !== null && $targetUuid !== '') {
            $target = is_string($targetUuid) ? WorkflowProject::query()->where('uuid', $targetUuid)->first() : null;
            if (! $target || $target->id === $model->id) {
                return ires()->error()->code(422)->errorCode('error_workflow_invalid')->message('Choose another workflow project.');
            }
            if (! $request->user()?->can('update', $target)) {
                return ires()->forbidden(__('mgs.no.permission'));
            }
            if (! WorkflowTriggers::startsWith($target, WorkflowTriggers::ERROR)) {
                return ires()->error()->code(422)->errorCode('error_workflow_wrong_trigger')
                    ->message('The error workflow must start with an error trigger.');
            }
        }

        $before = $model->error_workflow_project_id;
        $model->forceFill(['error_workflow_project_id' => $target?->id])->save();
        if ($before !== $model->error_workflow_project_id) {
            WorkflowAudit::project(WorkflowAudit::ERROR_WORKFLOW_CHANGED, $model, [
                'from' => $before,
                'to' => $model->error_workflow_project_id,
            ], 'warning', $this->actorId($request));
        }

        return ires()->data(['error_workflow_uuid' => $target?->uuid]);
    }

    protected function actorId(Request $request): ?int
    {
        $id = $request->user()?->getKey();

        return is_numeric($id) ? (int) $id : null;
    }

    protected function authorizedProject(Request $request, string $project): WorkflowProject|AppResponse
    {
        $model = WorkflowProject::query()->where('uuid', $project)->first();
        if (! $model) {
            return ires()->notFound(__('mgs.model.404'));
        }

        if (! $request->user()?->can('update', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeWebhook(WorkflowProject $project): array
    {
        $token = $project->webhook_token;
        $start = WorkflowTriggers::startStep($project);
        $auth = $start?->step_type === WorkflowTriggers::WEBHOOK ? ($start->step_config['auth'] ?? 'hmac') : null;

        return [
            'enabled' => $token !== null,
            'url' => $token ? url('api/v2/workflow/webhooks/' . $token) : null,
            'auth' => $auth,
            'starts_with_webhook' => $start?->step_type === WorkflowTriggers::WEBHOOK,
            // How a caller signs (hmac mode): the timestamp is part of the signed string; replays are refused.
            'signing' => $auth === 'token'
                ? ['token_header' => WebhookTriggerService::TOKEN_HEADER]
                : [
                    'timestamp_header' => WebhookTriggerService::TIMESTAMP_HEADER,
                    'signature_header' => WebhookTriggerService::SIGNATURE_HEADER,
                    'signature' => 'sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">',
                    'tolerance_seconds' => WebhookTriggerService::TOLERANCE_SECONDS,
                ],
        ];
    }
}
