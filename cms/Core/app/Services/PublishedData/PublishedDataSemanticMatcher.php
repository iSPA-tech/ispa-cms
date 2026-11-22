<?php

namespace Cms\Core\Services\PublishedData;

final class PublishedDataSemanticMatcher
{
    public const CLASS_DUPLICATE = 'duplicate';
    public const CLASS_RELATED_CLUSTER = 'related_cluster';
    public const CLASS_NEW_CANDIDATE = 'new_candidate';

    public function __construct(
        private readonly PublishedDataProfileService $profileService = new PublishedDataProfileService(),
    ) {
    }

    public function sanitizeVector(array $vector): array
    {
        return array_values(array_map(
            static fn (mixed $value): float => (float) $value,
            array_filter(
                $vector,
                static fn (mixed $value): bool => is_numeric($value) && is_finite((float) $value),
            ),
        ));
    }

    public function cosineSimilarity(array $left, array $right): float
    {
        $left = $this->sanitizeVector($left);
        $right = $this->sanitizeVector($right);

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        $count = max(count($left), count($right));

        for ($i = 0; $i < $count; $i++) {
            $leftValue = $left[$i] ?? 0.0;
            $rightValue = $right[$i] ?? 0.0;

            $dot += $leftValue * $rightValue;
            $leftNorm += $leftValue * $leftValue;
            $rightNorm += $rightValue * $rightValue;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    public function classifyScore(float $score, string $profileSlug): string
    {
        $profile = $this->profileService->getProfile($profileSlug);
        $normalizedScore = min(1.0, max(0.0, $score));

        if ($normalizedScore >= $profile->duplicateThreshold) {
            return self::CLASS_DUPLICATE;
        }

        if ($normalizedScore >= $profile->relatedClusterThreshold) {
            return self::CLASS_RELATED_CLUSTER;
        }

        return self::CLASS_NEW_CANDIDATE;
    }

    public function classifyVectors(array $left, array $right, string $profileSlug): string
    {
        return $this->classifyScore($this->cosineSimilarity($left, $right), $profileSlug);
    }

    /**
     * @return array{score: float, classification: string}
     */
    public function matchVectors(array $left, array $right, string $profileSlug): array
    {
        $score = $this->cosineSimilarity($left, $right);

        return [
            'score' => round($score, 6),
            'classification' => $this->classifyScore($score, $profileSlug),
        ];
    }
}
