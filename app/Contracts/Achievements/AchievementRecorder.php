<?php

declare(strict_types=1);

namespace App\Contracts\Achievements;

/**
 * Core-app decoupling contract for the (future) Achievements module.
 *
 * Producer modules (e.g. QuestionBank) resolve this
 * contract via the container and call it to record achievement-worthy
 * domain events. When the Achievements module is not installed, the
 * default NullAchievementRecorder binding keeps callers safe: no
 * exceptions, no hard dependency, no inline engine execution.
 */
interface AchievementRecorder
{
    /**
     * Enqueue an idempotent domain event (afterCommit). Never throws to
     * the caller; never runs the achievement engine inline.
     *
     * @param array<string, mixed> $payload
     */
    public function record(string $eventName, int $userId, string $idempotencyKey, array $payload = []): void;

    /**
     * Explicit grant of a known achievement by key (manual/admin). Idempotent.
     *
     * @param array<string, mixed> $context
     * @return int|null The award id, or null if no award was made.
     */
    public function grantByKey(int $userId, string $achievementKey, array $context = []): ?int;
}
