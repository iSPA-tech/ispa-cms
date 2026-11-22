<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

/**
 * Result of a gateway completion, including which model actually answered.
 */
final readonly class AiGatewayResult
{
    /**
     * @param array<string, mixed> $response Raw provider response (`[]` on failure or refusal).
     * @param string|null $model Model of the last attempt (the answering one on success).
     * @param string $provider Provider name used for the ledger.
     * @param bool $fallbackUsed True when the response came from a fallback model.
     * @param string|null $refusedReason Budget refusal reason, when refused.
     * @param int $attempts Number of provider calls made.
     */
    public function __construct(
        public array $response,
        public ?string $model,
        public string $provider,
        public bool $fallbackUsed,
        public ?string $refusedReason,
        public int $attempts,
    ) {
    }
}
