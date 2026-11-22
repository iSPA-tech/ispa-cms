<?php

declare(strict_types=1);

namespace App\Contracts\Achievements;

/**
 * Provider-owned achievement catalog contract. Producer modules (e.g.
 * QuestionBank) implement this to declare their own
 * achievement definitions WITHOUT depending on the Achievements module —
 * only this app-level contract. The Achievements module's
 * Cms\Achievements\Services\AchievementCatalogSynchronizer resolves the
 * implementations listed in config('achievements.providers') via the
 * container and syncs the returned definitions into the `achievements` /
 * `achievement_title_ladders` / `achievement_event_subscriptions` tables.
 *
 * Definition array shape (one entry per achievement):
 * [
 *     'key' => 'mustard_seed.title.nguoi_dong_hanh',   // globally-unique, namespaced
 *     'display_type' => 'title'|'badge',                // AchievementDisplayType value
 *     'category' => 'mustard_seed'|'checkin'|...,        // AchievementCategory value
 *     'award_method' => 'threshold'|'manual'|...,        // AchievementAwardMethod value
 *     'source_module' => 'example_module',
 *     'criteria_version' => 1|null,
 *     'criteria' => [...]|null,                           // params only, NO method/version keys
 *     'status' => 'active',
 *     'presentation' => [                                 // OPERATOR-OWNED (preserved on re-seed)
 *         'name' => '...', 'icon' => '...', 'short_description' => null, 'description' => null,
 *         'rarity' => 'common', 'is_secret' => false, 'is_equipable' => true, 'is_repeatable' => false,
 *         'max_recipients' => null, 'visibility' => 'public',
 *         'share_message' => null, 'earned_message' => null, 'unearned_hint' => null, 'sort_order' => 0,
 *     ],
 *     'ladder' => ['ladder_key' => 'mustard_seed.lifetime_earned', 'threshold' => 0, 'rank' => 1]|null, // titles only
 *     'subscriptions' => [ ['event_name' => '...', 'metric' => '...'|null], ... ],  // for auto-evaluated defs
 * ]
 */
interface AchievementDefinitionProvider
{
    /** @return array<int, array<string, mixed>> list of achievement definitions */
    public function definitions(): array;
}
