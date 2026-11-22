<?php

declare(strict_types=1);

namespace App\Contracts\Achievements;

/**
 * Core-app decoupling contract for READING the Achievements catalog/profile.
 *
 * The read-side mirror of App\Contracts\Achievements\AchievementRecorder:
 * consumer modules (a content or loyalty module) resolve this contract via the
 * container to read achievement titles/ladders WITHOUT depending on the
 * Achievements module. When the Achievements module is not installed, the
 * default NullAchievementReader binding keeps callers safe: empty payloads,
 * no exceptions, no hard dependency.
 *
 * Two surfaces:
 *  - Ladder-derived reads (titlePayloadForLifetime / titleLadder /
 *    titleThresholdByKey / available) derive the current/next title purely
 *    from the ladder thresholds — NOT from a user's granted awards. These
 *    back a consumer module's wallet summary.
 *  - User-scoped reads/equips (titlePayload / collection / progressList /
 *    loadout / equipTitle / equipBadge / setPublicDisplay / publicLoadout)
 *    delegate to the module's AchievementProfileService and back the mobile
 *    profile surface wired in a later task.
 */
interface AchievementReader
{
    /**
     * Derived current/next title for a lifetime-earned total, resolved by
     * ladder threshold (NOT by granted awards). Shape mirrors a consumer module's
     * legacy wallet-summary title payload exactly:
     * {id, key, name, symbol, description, threshold, next_name, next_required_amount}.
     *
     * @return array<string, mixed>
     */
    public function titlePayloadForLifetime(int $lifetimeEarned): array;

    /**
     * The full active title ladder, ascending by threshold. Each tier:
     * {id, key, name, symbol, description, threshold, rank}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function titleLadder(): array;

    /**
     * The ladder threshold for a title achievement key, or null when the key
     * is unknown/inactive.
     */
    public function titleThresholdByKey(string $key): ?int;

    /**
     * Whether the Achievements catalog is usable (schema present).
     */
    public function available(): bool;

    /**
     * @return array<string, mixed>
     */
    public function titlePayload(int $userId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collection(int $userId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function progressList(int $userId): array;

    /**
     * @return array<string, mixed>
     */
    public function loadout(int $userId): array;

    /**
     * Raw persisted loadout row for a user, or null when none exists yet.
     *
     * Unlike loadout() (the semantic equipped-items read model), this exposes
     * the storage row identity a consumer module needs to reconstruct its legacy
     * `mustard_seed_user_profiles` response shape without depending on the
     * Achievements module: {id, user_id, equipped_title_achievement_id,
     * equipped_badge_award_id, equipped_badge_achievement_id,
     * public_display_enabled, metadata, created_at, updated_at}.
     * `equipped_badge_achievement_id` is the equipped badge award's achievement
     * id, resolved so consumers can echo a badge identifier without a second
     * lookup.
     *
     * @return array<string, mixed>|null
     */
    public function loadoutRecord(int $userId): ?array;

    /**
     * Set and/or clear equipped loadout slots in one write, mirroring the legacy
     * updateOrInsert semantics: a present key is written (an explicit null CLEARS
     * that slot); an absent key is left unchanged. Callers MUST have already
     * validated ownership/unlock — this performs no validation and no grant.
     * Recognised keys: `equipped_title_achievement_id` (int|null),
     * `equipped_badge_award_id` (int|null), `public_display_enabled` (bool).
     * Always materialises the row and returns loadoutRecord() (never null).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateLoadout(int $userId, array $changes): array;

    /**
     * @return array<string, mixed>
     */
    public function equipTitle(int $userId, int $achievementId): array;

    /**
     * @return array<string, mixed>
     */
    public function equipBadge(int $userId, int $awardId): array;

    /**
     * @return array<string, mixed>
     */
    public function setPublicDisplay(int $userId, bool $enabled): array;

    /**
     * @return array<string, mixed>
     */
    public function publicLoadout(int $userId): array;
}
