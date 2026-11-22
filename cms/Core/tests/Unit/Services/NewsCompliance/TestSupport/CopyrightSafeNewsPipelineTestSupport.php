<?php

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function newsComplianceAiResponse(array $payload): array
{
    if (array_key_exists('headline', $payload) && ! array_key_exists('sentence_fact_map', $payload)) {
        $payload['sentence_fact_map'] = newsComplianceSentenceFactMap($payload);
    }

    return [
        'choices' => [[
            'index' => 0,
            'message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return list<array<string, mixed>>
 */
function newsComplianceSentenceFactMap(array $payload): array
{
    $fallbackFacts = [];
    foreach ((array) ($payload['sections'] ?? []) as $section) {
        foreach ((array) data_get($section, 'facts_used', []) as $fact) {
            $fact = trim((string) $fact);
            if ($fact !== '') {
                $fallbackFacts[] = $fact;
            }
        }
    }
    $fallbackFacts = array_values(array_unique($fallbackFacts ?: ['f1']));

    $sentences = [];
    foreach ([
        (string) ($payload['intro'] ?? ''),
        ...array_map(static fn (mixed $section): string => (string) data_get($section, 'body', ''), (array) ($payload['sections'] ?? [])),
    ] as $text) {
        $text = trim($text);
        if ($text !== '') {
            $sentences[] = $text;
        }
    }

    return array_map(static fn (string $sentence): array => [
        'sentence_text' => $sentence,
        'sentence_type' => 'fact',
        'approved_fact_ids' => $fallbackFacts,
        'approved_inference_id' => '',
        'claim_status' => 'verified_fact',
        'attribution_in_sentence' => true,
        'blocked_fact_hit' => false,
        'source_similarity_score' => 0.0,
        'fact_sheet_similarity_score' => 0.0,
    ], $sentences);
}
