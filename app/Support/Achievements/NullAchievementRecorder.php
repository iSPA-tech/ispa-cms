<?php

declare(strict_types=1);

namespace App\Support\Achievements;

use App\Contracts\Achievements\AchievementRecorder;

/**
 * Default no-op implementation of AchievementRecorder.
 *
 * Bound as the default singleton so any module can safely record
 * achievement events even when the Achievements module is not installed.
 * The real Achievements module (built later) rebinds this contract to a
 * real implementation.
 */
final class NullAchievementRecorder implements AchievementRecorder
{
    public function record(string $eventName, int $userId, string $idempotencyKey, array $payload = []): void
    {
        // No-op: Achievements module is not installed.
    }

    public function grantByKey(int $userId, string $achievementKey, array $context = []): ?int
    {
        // No-op: Achievements module is not installed.
        return null;
    }
}
