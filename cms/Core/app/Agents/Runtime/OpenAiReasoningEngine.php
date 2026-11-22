<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-backed reasoning engine using function-calling for tool selection.
 *
 * Receives the agent's current state, formats available tools as OpenAI
 * function definitions, sends to the LLM, and parses the response into
 * an AgentDecision (tool call or terminal).
 *
 * @see ReasoningEngineInterface
 * @see AgenticAiProviderInterface
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0
 */
class OpenAiReasoningEngine implements ReasoningEngineInterface
{
    public function __construct(
        protected AgenticAiProviderInterface $aiProvider,
        protected AgentToolRegistry $toolRegistry,
        protected ToolDefinitionFormatter $formatter,
        protected ?AiTaskRuntime $runtime = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function plan(AgentState $state): AgentDecision
    {
        $tools = $this->formatter->format(
            $this->toolRegistry->definitions(),
            $state->availableToolDefinitions,
        );

        if ($tools === []) {
            return AgentDecision::terminal('failed', 'no_tools_available');
        }

        $messages = $this->buildMessages($state);
        $payload = $this->buildPayload($messages, $tools, $state);

        try {
            if ($this->runtime !== null) {
                $result = $this->runtime->run('agentic_reasoning', $payload, $this->aiProvider);
                $response = $result['response'];
                if ($result['refused_reason'] !== null) {
                    Log::warning('Agentic reasoning skipped: AI budget refused the call', ['reason' => $result['refused_reason']]);
                }
            } else {
                $response = $this->aiProvider->createCompletion($payload);
            }
        } catch (\Throwable $e) {
            return AgentDecision::terminal('failed', 'ai_provider_error: ' . $e->getMessage());
        }

        return $this->parseResponse($response);
    }

    /**
     * Build the chat messages for the LLM.
     *
     * @param  AgentState $state The current agent state.
     * @return array<int, array<string, string>> The messages array.
     */
    protected function buildMessages(AgentState $state): array
    {
        $systemPrompt = $this->buildSystemPrompt($state);
        $userMessage = $this->buildUserMessage($state);

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];
    }

    /**
     * Build the system prompt from agent state.
     *
     * @param  AgentState $state The current agent state.
     * @return string The system prompt.
     */
    protected function buildSystemPrompt(AgentState $state): string
    {
        $parts = [
            'You are an AI agent operating within the iSPA CMS agentic runtime.',
            "Your goal: {$state->goal}",
            "Module scope: {$state->moduleScope}",
            "Capability class: {$state->profileCapabilityClass}",
        ];

        if ($state->budgetRemaining !== []) {
            $budgetLines = [];
            foreach ($state->budgetRemaining as $key => $value) {
                if ($value !== null) {
                    $budgetLines[] = "{$key}: {$value} remaining";
                }
            }
            if ($budgetLines !== []) {
                $parts[] = 'Budget constraints: ' . implode(', ', $budgetLines);
            }
        }

        $parts[] = 'Select the most appropriate tool to advance toward the goal. If the goal is already met or cannot be achieved, respond with a text message instead of calling a tool.';

        return implode("\n", $parts);
    }

    /**
     * Build the user message from agent context.
     *
     * @param  AgentState $state The current agent state.
     * @return string The user message.
     */
    protected function buildUserMessage(AgentState $state): string
    {
        $parts = [];

        if ($state->contextSnapshot !== []) {
            $parts[] = 'Context: ' . json_encode($state->contextSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($state->workingMemory !== []) {
            $parts[] = 'Working memory: ' . json_encode($state->workingMemory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($state->lastAction !== null) {
            $parts[] = "Last action: {$state->lastAction}";
        }

        if ($parts === []) {
            $parts[] = "Please proceed with the goal: {$state->goal}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build the OpenAI API payload.
     *
     * @param  array<int, array<string, string>> $messages The chat messages.
     * @param  array<int, array<string, mixed>>  $tools    The formatted tools.
     * @param  AgentState                        $state    The agent state.
     * @return array<string, mixed> The API payload.
     */
    protected function buildPayload(array $messages, array $tools, AgentState $state): array
    {
        return [
            // gpt-4o is being retired; OpenAIClient adapts temperature / max_tokens for GPT-5.x models.
            'model' => (string) config('core.agentic.reasoning_model', 'gpt-5.6-terra'),
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'temperature' => 0.2,
            'max_tokens' => 2048,
        ];
    }

    /**
     * Parse the OpenAI response into an AgentDecision.
     *
     * @param  array<string, mixed> $response The raw API response.
     * @return AgentDecision The parsed decision.
     */
    protected function parseResponse(array $response): AgentDecision
    {
        $choices = $response['choices'] ?? [];
        if ($choices === []) {
            return AgentDecision::terminal('failed', 'empty_response');
        }

        $firstChoice = $choices[0] ?? [];
        $message = $firstChoice['message'] ?? [];
        $finishReason = $firstChoice['finish_reason'] ?? null;

        $toolCalls = $message['tool_calls'] ?? [];
        if ($toolCalls !== []) {
            return $this->parseToolCall($toolCalls[0]);
        }

        $content = trim($message['content'] ?? '');
        if ($content !== '') {
            return AgentDecision::terminal('complete', $content);
        }

        return AgentDecision::terminal('failed', 'unparseable_response');
    }

    /**
     * Parse a single tool call from the response.
     *
     * @param  array<string, mixed> $toolCall The tool call data.
     * @return AgentDecision The tool call decision.
     */
    protected function parseToolCall(array $toolCall): AgentDecision
    {
        $function = $toolCall['function'] ?? [];
        $name = $function['name'] ?? '';
        $argumentsRaw = $function['arguments'] ?? '{}';

        if ($name === '') {
            return AgentDecision::terminal('failed', 'tool_call_missing_name');
        }

        $input = [];
        if (is_string($argumentsRaw)) {
            try {
                $input = (array) json_decode($argumentsRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $input = [];
            }
        } elseif (is_array($argumentsRaw)) {
            $input = $argumentsRaw;
        }

        return AgentDecision::tool($name, $input);
    }
}
