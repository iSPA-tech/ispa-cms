<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/*
 * API token security. Read as `core.token_security.*` (merged by the module service provider).
 *
 * `stepup`: the fresh confirmation every token revoke needs. The caller proves who they are again (password,
 * plus the second factor when the account has one, or an e-mail code when the account has no password) and gets a
 * short-lived grant bound to the user and to the token that made the request. Failures are counted per account, across
 * tokens, addresses and restarted challenges, and lock the confirmation of that account for a while.
 */
return [
    'stepup' => [
        // Minutes a grant stays valid. Single-token revokes may reuse it within this time; bulk revokes consume it.
        'minutes' => 5,
        // Minutes a started confirmation (waiting for the second factor) stays open.
        'challenge_minutes' => 10,
        // Failed attempts (wrong password or code) allowed per account within the window before the lock.
        'max_failures' => 5,
        'failure_window_minutes' => 15,
        // Lock length, and the longer lock from the n-th lockout within the escalation window on.
        'lock_minutes' => 15,
        'escalated_lock_minutes' => 60,
        'escalate_from_lockout' => 3,
        'escalation_window_hours' => 24,
        // E-mail codes: at most this many sends per account and hour; each code lives and allows this much.
        'email_codes_per_hour' => 3,
        'email_code_minutes' => 10,
        'email_code_attempts' => 5,
        // Cache store for counters, locks, challenges and grants (null = the default store). It must be shared by every
        // web server of the site. When it fails, the confirmation fails closed (503, no grant).
        'cache_store' => env('SECURITY_STEPUP_CACHE_STORE'),
    ],
];
