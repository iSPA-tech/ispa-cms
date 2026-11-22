<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Public;

use Cms\Core\Http\Middleware\AgentAnswerEnvelope;
use Cms\Core\Services\Agentic\EphemeralAgentToolExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * POST /api/v2/public/agent/answers: one authenticated question, answered only from digest-approved
 * Content snapshots with verified quote citations.
 *
 * The profile and tool are fixed server-side; the project comes from `ispa-cms.folder_name`. Internal error codes,
 * exception text, provider responses and retrieval metadata never reach the client.
 */
final class AgentAnswerController extends Controller
{
    public const PROFILE = 'public-content-assistant';
    public const TOOL = 'content.answer_posts';

    private const INSUFFICIENT = [
        'vi' => 'Không đủ dữ liệu đã được phê duyệt để trả lời câu hỏi này.',
        'en' => 'The approved corpus does not contain enough evidence to answer this question.',
    ];

    public function __invoke(Request $request, EphemeralAgentToolExecutor $executor): JsonResponse
    {
        if ($request->user()?->can('agent_profile.answer') !== true) {
            return AgentAnswerEnvelope::error($request, 403);
        }

        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'min:1', 'max:1200'],
            'language' => ['nullable', Rule::in(['vi', 'en'])],
        ]);
        $question = is_string($request->input('question')) ? trim($request->input('question')) : '';
        if ($validator->fails() || $question === '') {
            return AgentAnswerEnvelope::error($request, 422);
        }
        $language = (string) ($request->input('language') ?? 'vi');

        if (config('core.agent_answers.enabled') !== true
            || ! in_array(config('ispa-cms.folder_name'), (array) config('core.agent_answers.allowed_projects', []), true)) {
            return AgentAnswerEnvelope::error($request, 503);
        }

        try {
            $result = $executor->execute(
                self::PROFILE,
                self::TOOL,
                ['question' => $question, 'language' => $language, 'limit' => 5],
                'Content',
            );
        } catch (Throwable) {
            // EphemeralAgentToolException (module, profile, grant, policy, binding, input, tool failure) or any
            // other runtime failure: the reason stays internal.
            return AgentAnswerEnvelope::error($request, 503);
        }

        return match ($result['outcome'] ?? null) {
            'answered' => AgentAnswerEnvelope::success(
                $request,
                'answered',
                (string) $result['answer'],
                (bool) ($result['conflict'] ?? false),
                $result['as_of'] ?? null,
                array_values((array) ($result['citations'] ?? [])),
            ),
            'insufficient_evidence' => AgentAnswerEnvelope::success(
                $request,
                'insufficient_evidence',
                self::INSUFFICIENT[$language],
                false,
                $result['as_of'] ?? null,
                [],
            ),
            'contract_error' => AgentAnswerEnvelope::error($request, 502),
            default => AgentAnswerEnvelope::error($request, 503),
        };
    }
}
