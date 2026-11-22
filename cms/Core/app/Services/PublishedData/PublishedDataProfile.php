<?php

namespace Cms\Core\Services\PublishedData;

final class PublishedDataProfile
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly float $duplicateThreshold,
        public readonly float $relatedClusterThreshold,
        public readonly bool $strictVerificationRequired,
        public readonly bool $allowPublishedFirst,
        public readonly bool $requiresContentQualityCheck,
        public readonly bool $requiresCopyrightQualityCheck,
        public readonly bool $requiresSourceQualityCheck,
        public readonly array $qualityChecks = [],
        public readonly array $requiredEvidence = [],
        public readonly array $candidateTypes = [],
    ) {
    }
}
