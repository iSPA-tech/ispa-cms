<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Jev;

/**
 * A JEV judge's answer. `verdict` is pass / fail / unsure; anything that is not an assessment (disabled, unavailable,
 * budget refused, invalid answer) is `unsure`, so a caller never treats a missing judgement as a pass.
 *
 * @package Cms\Core\Services\Ai\Jev
 * @since   2026-11-24
 */
final class JevVerdict
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const UNSURE = 'unsure';

    public const STATUSES = ['assessed', 'disabled', 'skipped', 'unavailable', 'budget_refused'];

    /**
     * @param list<string>              $reasons
     * @param array<string, mixed>|null $usage   `meta.usage` block (AiUsageMeter shape) or null when no call was made
     */
    public function __construct(
        public readonly string $status,
        public readonly string $verdict,
        public readonly ?float $confidence = null,
        public readonly array $reasons = [],
        public readonly string $mode = 'active',
        public readonly ?string $model = null,
        public readonly ?array $usage = null,
    ) {
    }

    public static function unavailable(string $status, string $reason, string $mode = 'active', ?array $usage = null): self
    {
        return new self(in_array($status, self::STATUSES, true) ? $status : 'unavailable', self::UNSURE, null, [$reason], $mode, null, $usage);
    }

    public function assessed(): bool
    {
        return $this->status === 'assessed';
    }

    /**
     * Whether this verdict may decide on its own: assessed, in active mode, not unsure, and at least as confident as
     * the threshold.
     */
    public function decides(float $minConfidence): bool
    {
        return $this->assessed()
            && $this->mode === 'active'
            && $this->verdict !== self::UNSURE
            && $this->confidence !== null
            && $this->confidence >= $minConfidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'verdict' => $this->verdict,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'mode' => $this->mode,
            'model' => $this->model,
        ];
    }
}
