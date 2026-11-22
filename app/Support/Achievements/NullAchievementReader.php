<?php

declare(strict_types=1);

namespace App\Support\Achievements;

use App\Contracts\Achievements\AchievementReader;

/**
 * Default no-op implementation of AchievementReader.
 *
 * Bound as the default singleton so any consumer can safely read achievement
 * titles/ladders even when the Achievements module is not installed. The real
 * Achievements module rebinds this contract to a real implementation. Every
 * method returns the same "empty" shape the caller would otherwise render for
 * a user with no title, so no branch in the consumer needs a module check.
 */
final class NullAchievementReader implements AchievementReader
{
    public function titlePayloadForLifetime(int $lifetimeEarned): array
    {
        return [
            'id' => null,
            'key' => null,
            'name' => '',
            'symbol' => '',
            'description' => '',
            'threshold' => 0,
            'next_name' => null,
            'next_required_amount' => 0,
        ];
    }

    public function titleLadder(): array
    {
        return [];
    }

    public function titleThresholdByKey(string $key): ?int
    {
        return null;
    }

    public function available(): bool
    {
        return false;
    }

    public function titlePayload(int $userId): array
    {
        return [];
    }

    public function collection(int $userId): array
    {
        return [];
    }

    public function progressList(int $userId): array
    {
        return [];
    }

    public function loadout(int $userId): array
    {
        return [];
    }

    public function loadoutRecord(int $userId): ?array
    {
        return null;
    }

    public function updateLoadout(int $userId, array $changes): array
    {
        return [];
    }

    public function equipTitle(int $userId, int $achievementId): array
    {
        return [];
    }

    public function equipBadge(int $userId, int $awardId): array
    {
        return [];
    }

    public function setPublicDisplay(int $userId, bool $enabled): array
    {
        return [];
    }

    public function publicLoadout(int $userId): array
    {
        return [];
    }
}
