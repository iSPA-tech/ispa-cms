<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Account-wide counters of the step-up confirmation: failed attempts, the lock and its escalation, and the
 * hourly limit of e-mail codes. Every key is derived from a keyed hash of the user id, so the counters are shared by
 * every token, every address and every restarted confirmation of that account and never touch another account.
 *
 * Attempts are counted before they are checked (`begin`), so parallel requests cannot try more than the limit; a
 * proof that succeeds gives its slot back (`succeeded`), a full confirmation clears the counter (`clear`). A cache
 * failure throws {@see StepUpException::unavailable()}: the confirmation fails closed.
 *
 * @package Cms\Core\Services\Security
 */
final class StepUpThrottle
{
    private const PREFIX = 'security:stepup:';

    /**
     * Refuse when the confirmation of the account is locked.
     *
     * @param User $user Account.
     * @return void
     * @throws StepUpException Locked (429) or cache failure (503).
     */
    public function assertNotLocked(User $user): void
    {
        $retryAfter = $this->retryAfter($user);
        if ($retryAfter !== null) {
            throw StepUpException::locked($retryAfter);
        }
    }

    /**
     * Seconds until the lock of the account ends, or null when it is not locked.
     *
     * @param User $user Account.
     * @return int|null
     * @throws StepUpException Cache failure (503).
     */
    public function retryAfter(User $user): ?int
    {
        $until = $this->guard(fn (Repository $cache): mixed => $cache->get($this->key($user, 'lock')));
        if ($until === null) {
            return null;
        }
        $left = (int) $until - now()->getTimestamp();

        return $left > 0 ? $left : null;
    }

    /**
     * Reserve one attempt before a password or a code is checked. Refuses when the account is locked or when the
     * attempt would exceed the limit (which then locks the account).
     *
     * @param User $user Account.
     * @return void
     * @throws StepUpException Locked (429) or cache failure (503).
     */
    public function begin(User $user): void
    {
        $this->assertNotLocked($user);
        $count = $this->guard(function (Repository $cache) use ($user): int {
            $key = $this->key($user, 'failures');
            $cache->add($key, 0, $this->minutes('failure_window_minutes', 15) * 60);

            return (int) $cache->increment($key);
        });

        if ($count > $this->maxFailures()) {
            [, $retryAfter] = $this->lock($user);
            throw StepUpException::locked($retryAfter);
        }
    }

    /**
     * A proof succeeded but the confirmation is not complete yet: give the reserved slot back.
     *
     * @param User $user Account.
     * @return void
     * @throws StepUpException Cache failure (503).
     */
    public function succeeded(User $user): void
    {
        $this->guard(function (Repository $cache) use ($user): void {
            $key = $this->key($user, 'failures');
            if ((int) $cache->get($key, 0) > 0) {
                $cache->decrement($key);
            }
        });
    }

    /**
     * The reserved attempt failed. When it reached the limit, the account is locked.
     *
     * @param User $user Account.
     * @return array{failures: int, locked: bool, created: bool, retry_after: int|null, lockouts: int} `created` is true only for the call
     *     that started the lock (so the notice and the audit event are sent once).
     * @throws StepUpException Cache failure (503).
     */
    public function failed(User $user): array
    {
        $failures = (int) $this->guard(fn (Repository $cache): mixed => $cache->get($this->key($user, 'failures'), 0));
        if ($failures < $this->maxFailures()) {
            return ['failures' => $failures, 'locked' => false, 'created' => false, 'retry_after' => null, 'lockouts' => 0];
        }
        [$created, $retryAfter, $lockouts] = $this->lock($user);

        return ['failures' => $failures, 'locked' => true, 'created' => $created, 'retry_after' => $retryAfter, 'lockouts' => $lockouts];
    }

    /**
     * A full confirmation succeeded: clear the failure counter (the lockout history of the escalation window stays).
     *
     * @param User $user Account.
     * @return void
     * @throws StepUpException Cache failure (503).
     */
    public function clear(User $user): void
    {
        $this->guard(fn (Repository $cache): bool => $cache->forget($this->key($user, 'failures')));
    }

    /**
     * Reserve one e-mail code send within the hourly limit.
     *
     * @param User $user Account.
     * @return void
     * @throws StepUpException E-mail limit (429) or cache failure (503).
     */
    public function reserveEmailSend(User $user): void
    {
        $limit = max(1, (int) config('core.token_security.stepup.email_codes_per_hour', 3));
        [$count, $startedAt] = $this->guard(function (Repository $cache) use ($user): array {
            $key = $this->key($user, 'mail');
            $cache->add($key . ':start', now()->getTimestamp(), 3600);
            $cache->add($key, 0, 3600);

            return [(int) $cache->increment($key), (int) $cache->get($key . ':start', now()->getTimestamp())];
        });

        if ($count > $limit) {
            throw StepUpException::emailLimit($startedAt + 3600 - now()->getTimestamp());
        }
    }

    /**
     * Start the lock of the account (only the first caller creates it) and escalate it from the configured lockout on.
     *
     * @param User $user Account.
     * @return array{0: bool, 1: int, 2: int} [created, retry-after seconds, lockouts within the escalation window]
     * @throws StepUpException Cache failure (503).
     */
    private function lock(User $user): array
    {
        return $this->guard(function (Repository $cache) use ($user): array {
            $lockKey = $this->key($user, 'lock');
            $minutes = $this->minutes('lock_minutes', 15);
            if (! $cache->add($lockKey, now()->getTimestamp() + $minutes * 60, $minutes * 60)) {
                return [false, max(1, (int) $cache->get($lockKey, now()->getTimestamp() + 1) - now()->getTimestamp()), 0];
            }

            $historyKey = $this->key($user, 'lockouts');
            $cache->add($historyKey, 0, $this->minutes('escalation_window_hours', 24) * 3600);
            $lockouts = (int) $cache->increment($historyKey);
            if ($lockouts >= max(1, (int) config('core.token_security.stepup.escalate_from_lockout', 3))) {
                $minutes = $this->minutes('escalated_lock_minutes', 60);
                $cache->put($lockKey, now()->getTimestamp() + $minutes * 60, $minutes * 60);
            }
            $cache->forget($this->key($user, 'failures'));

            return [true, $minutes * 60, $lockouts];
        });
    }

    /**
     * Run a cache operation; any failure of the store fails closed.
     *
     * @template T
     * @param Closure(Repository): T $operation
     * @return T
     * @throws StepUpException Cache failure (503).
     */
    private function guard(Closure $operation): mixed
    {
        try {
            return $operation(self::store());
        } catch (StepUpException $e) {
            throw $e;
        } catch (Throwable) {
            throw StepUpException::unavailable();
        }
    }

    /**
     * The cache store of the step-up state.
     *
     * @return Repository
     */
    public static function store(): Repository
    {
        $name = config('core.token_security.stepup.cache_store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * Cache key of one counter of the account: a keyed hash of the user id, never the id itself.
     *
     * @param User $user Account.
     * @param string $kind Counter name.
     * @return string
     */
    private function key(User $user, string $kind): string
    {
        return self::PREFIX . $kind . ':' . hash_hmac('sha256', 'stepup-user|' . $user->getKey(), (string) config('app.key'));
    }

    /**
     * Maximum failed attempts within the window.
     *
     * @return int
     */
    private function maxFailures(): int
    {
        return max(1, (int) config('core.token_security.stepup.max_failures', 5));
    }

    /**
     * A positive integer setting of `core.token_security.stepup`.
     *
     * @param string $key Setting name.
     * @param int $default Default value.
     * @return int
     */
    private function minutes(string $key, int $default): int
    {
        return max(1, (int) config('core.token_security.stepup.' . $key, $default));
    }
}
