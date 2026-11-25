<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Ai\AiRuntimeBudgetRequest;
use Cms\Core\Http\Requests\Ai\AiRuntimeProvidersRequest;
use Cms\Core\Http\Requests\Ai\AiRuntimeTasksRequest;
use Cms\Core\Http\Requests\Ai\AiRuntimeTestRequest;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Ai\AiModelChoices;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\Usage\AiCallLog;
use Cms\Core\Services\Ai\Usage\AiUsageLedger;
use Illuminate\Http\Request;

final class AiRuntimeController extends AdminController
{
    public function __construct(
        private readonly AiRuntimeSettingsService $settings,
    ) {
        parent::__construct();
    }

    public function index(): AppResponse
    {
        return ires()->data($this->settings->overview());
    }

    /**
     * Provider + model pairs an AI node or a rewrite may pick (`{value, provider, provider_label, model, price_known}`).
     * Guarded like the workflow builder's node catalog (`workflow_project.viewAny`), so authors without AI Runtime rights
     * can use it; never carries an API key or base URI.
     */
    public function selectableModels(Request $request, AiModelChoices $choices): AppResponse
    {
        if (!$request->user()?->can('workflow_project.viewAny')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return ires()->data($choices->selectable());
    }

    public function updateProviders(AiRuntimeProvidersRequest $request): AppResponse
    {
        if ($request->exists('default_provider')) {
            $this->settings->saveDefaultProvider((string) ($request->validated('default_provider') ?? ''));
        }
        $this->settings->saveProviders($request->validated('providers'));

        return ires()->data($this->settings->overview())->message(__('message.update_success'));
    }

    public function updateTasks(AiRuntimeTasksRequest $request): AppResponse
    {
        $this->settings->saveTasks($request->validated('tasks'));

        return ires()->data($this->settings->overview())->message(__('message.update_success'));
    }

    /**
     * Save the monthly LLM budget (cap, kill switch, warning percent).
     */
    public function updateBudget(AiRuntimeBudgetRequest $request): AppResponse
    {
        $this->settings->saveBudget($request->validated());

        return ires()->data($this->settings->overview())->message(__('message.update_success'));
    }

    /**
     * LLM usage summary of one UTC month (`month` query, `Y-m`).
     */
    public function usage(Request $request, AiUsageLedger $ledger): AppResponse
    {
        $month = $request->query('month');

        return ires()->data($ledger->summary(is_string($month) ? $month : null));
    }

    /**
     * Recent logged AI calls of one task plus its 7-day duplicate-prompt rate (`task`, `limit` 1..100).
     */
    public function calls(Request $request, AiCallLog $callLog): AppResponse
    {
        $data = $request->validate([
            'task' => ['required', 'string', 'max:150'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ires()->data($callLog->forTask($data['task'], (int) ($data['limit'] ?? 20)));
    }

    /**
     * Fire one tiny structured completion through the registry-resolved
     * provider so the admin can verify credentials and model access.
     */
    public function testConnection(AiRuntimeTestRequest $request, AiStructuredExtractionService $extractor): AppResponse
    {
        $provider = (string) $request->validated('provider');
        $model = trim((string) ($request->validated('model') ?? ''));
        $startedAt = microtime(true);

        $options = [
            'module' => 'Core',
            'task' => 'ai_runtime_connection_test',
            'schema_name' => 'ai_runtime_connection_test',
            'schema_version' => 'v1',
            'provider' => $provider,
            'cache_enabled' => false,
            'store' => false,
            'strict' => true,
            'temperature' => 0.0,
            'request_timeout_seconds' => 20,
            'request_max_attempts' => 1,
            'redact_provider_errors' => false,
        ];
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = $extractor->extract(
            input: '{"ping":true}',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['ok'],
                'properties' => ['ok' => ['type' => 'boolean']],
            ],
            instructions: 'Reply with {"ok":true}. No other output.',
            context: ['module' => 'Core', 'task' => 'ai_runtime_connection_test'],
            options: $options,
        );

        return ires()->data([
            'ok' => $result->ok,
            'provider' => $provider,
            'model' => $model !== '' ? $model : null,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'errors' => $result->ok ? [] : array_map(
                static fn (string $error): string => mb_substr($error, 0, 300),
                $result->errors,
            ),
        ]);
    }
}
