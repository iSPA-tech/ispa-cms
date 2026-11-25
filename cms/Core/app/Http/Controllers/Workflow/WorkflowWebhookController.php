<?php

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Services\Workflow\Triggers\WebhookTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public inbound webhook of a `trigger_webhook` workflow project: `POST api/v2/workflow/webhooks/{token}`.
 * No session or admin token: the per-project secret is the authentication (see WebhookTriggerService).
 *
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026.11 (2026-11-24)
 */
class WorkflowWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, WebhookTriggerService $webhooks): JsonResponse
    {
        $result = $webhooks->handle($request, $token);

        return response()->json($result['body'], $result['status']);
    }
}
