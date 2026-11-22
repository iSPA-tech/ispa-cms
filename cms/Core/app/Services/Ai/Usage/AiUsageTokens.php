<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

/**
 * Provider-neutral token counts of one LLM response.
 *
 * `input` excludes cached tokens, so the three counters can be priced independently.
 */
final readonly class AiUsageTokens
{
    /**
     * @param int $input Non-cached input tokens.
     * @param int $cached Cached input tokens.
     * @param int $output Output tokens.
     */
    public function __construct(
        public int $input = 0,
        public int $cached = 0,
        public int $output = 0,
    ) {
    }

    /**
     * Normalize the usage block of an OpenAI-style or Anthropic-style response.
     *
     * @param array<string, mixed> $response Raw decoded provider response.
     * @return self Zeros when the response carries no usage block.
     */
    public static function fromResponse(array $response): self
    {
        $usage = $response['usage'] ?? null;
        if (! is_array($usage)) {
            return new self();
        }

        $n = static fn (mixed $v): int => is_numeric($v) ? max(0, (int) $v) : 0;

        if (isset($usage['prompt_tokens']) || isset($usage['completion_tokens'])) {
            $cached = $n($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            $prompt = $n($usage['prompt_tokens'] ?? 0);

            return new self(max(0, $prompt - $cached), min($cached, $prompt), $n($usage['completion_tokens'] ?? 0));
        }

        // OpenAI Responses API: input_tokens includes the cached share.
        if (isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])) {
            $in = $n($usage['input_tokens'] ?? 0);
            $cached = min($n($usage['input_tokens_details']['cached_tokens'] ?? 0), $in);

            return new self($in - $cached, $cached, $n($usage['output_tokens'] ?? 0));
        }

        return new self(
            $n($usage['input_tokens'] ?? 0) + $n($usage['cache_creation_input_tokens'] ?? 0),
            $n($usage['cache_read_input_tokens'] ?? 0),
            $n($usage['output_tokens'] ?? 0),
        );
    }
}
