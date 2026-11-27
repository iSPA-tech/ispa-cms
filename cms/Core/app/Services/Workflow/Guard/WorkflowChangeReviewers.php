<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Guard;

use App\Models\User;
use Cms\Core\Models\Setting;
use InvalidArgumentException;
use Throwable;

/**
 * Who reviews workflow changes on this site ("Người duyệt thay đổi quy trình", the person who
 * set the site up, though a Webmaster, need not approve other people's changes nor hear about them).
 *
 * Stored in the setting `workflow.change_reviewers` as a JSON list of user uuids (Webmaster only, through
 * WorkflowGuardController; the generic settings endpoints never write it, SettingController::PROTECTED_KEYS).
 *
 * - Empty (the default): every holder of `workflow_project.publish` and every Webmaster review and are told, as before.
 * - Set: only the named people (existing accounts) are told about workflow changes and count as the "second publisher"
 *   of change control (WorkflowPublishService::otherPublisherExists()). A named person still needs `publish` to approve.
 *   Others who may publish keep that right; they are simply not asked.
 *
 * Every change is audited and told to the reviewers before and after it.
 *
 * @package Cms\Core\Services\Workflow\Guard
 * @since   2026.11 (2026-11-27)
 */
class WorkflowChangeReviewers
{
    public const SETTING_KEY = 'workflow.change_reviewers';

    /** Most people one site may name. */
    public const MAX = 20;

    /**
     * Uuids stored in the setting (may name accounts that no longer exist).
     *
     * @return list<string>
     */
    public function uuids(): array
    {
        try {
            $value = Setting::query()->where('setting_key', self::SETTING_KEY)->first()?->value;
        } catch (Throwable) {
            return [];
        }

        $list = is_array($value) ? $value : (is_string($value) && $value !== '' ? json_decode($value, true) : []);

        return array_values(array_unique(array_filter((array) $list, static fn ($uuid): bool => is_string($uuid) && $uuid !== '')));
    }

    /**
     * The named reviewers that still have an account, in name order. Empty when none is set.
     *
     * @return list<User>
     */
    public function users(): array
    {
        $uuids = $this->uuids();
        if ($uuids === []) {
            return [];
        }

        try {
            return User::query()->whereIn('uuid', $uuids)->orderBy('name')->get()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Name the reviewers (an empty list goes back to every publisher and Webmaster). Returns false when nothing changed.
     *
     * @param list<string> $uuids
     *
     * @throws InvalidArgumentException When a uuid names no account or the list is too long.
     */
    public function set(array $uuids, ?User $actor): bool
    {
        $uuids = array_values(array_unique(array_filter($uuids, static fn ($uuid): bool => is_string($uuid) && $uuid !== '')));
        if (count($uuids) > self::MAX) {
            throw new InvalidArgumentException('Name at most ' . self::MAX . ' reviewers.');
        }
        if ($uuids !== [] && User::query()->whereIn('uuid', $uuids)->count() !== count($uuids)) {
            throw new InvalidArgumentException('Choose people who have an account here.');
        }

        $before = $this->uuids();
        sort($before);
        $sorted = $uuids;
        sort($sorted);
        if ($before === $sorted) {
            return false;
        }

        $alerter = app(WorkflowChangeAlerter::class);
        $toldBefore = $alerter->recipients();

        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'text',
            'group_name' => 'workflow',
            'name' => 'change_reviewers',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'Workflow change reviewers'],
            'value' => json_encode($uuids),
        ])->save();

        WorkflowAudit::log(
            WorkflowAudit::REVIEWERS_CHANGED,
            ['from' => count($before), 'to' => count($uuids)],
            Setting::class,
            (int) $setting->getKey(),
            'warning',
            $actor ? (int) $actor->getKey() : null,
        );

        // Told to the reviewers before and after the change, once each.
        $people = [];
        foreach ([...$toldBefore, ...$alerter->recipients()] as $user) {
            $people[$user->getKey()] = $user;
        }
        $alerter->notifyUsers(array_values($people), 'reviewers_changed', null, ['count' => count($uuids)], $actor);

        return true;
    }
}
