<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Outcome of a step-up confirmation that is not a success. It renders itself as the JSON error envelope of the
 * admin API (`message`, `error_code`, `status`) with a stable lower-case error code the screens translate, and a
 * `Retry-After` header when the account is locked or the e-mail limit is reached. The message never repeats a password,
 * a code, a grant or a hash. A wrong password and a wrong code share one code and one message on purpose.
 *
 * @package Cms\Core\Services\Security
 */
final class StepUpException extends RuntimeException
{
    public const REQUIRED = 'step_up_required';
    public const FAILED = 'step_up_failed';
    public const LOCKED = 'step_up_locked';
    public const EMAIL_LIMIT = 'step_up_email_limit';
    public const PASSWORD_REQUIRED = 'step_up_password_required';
    public const NOT_POSSIBLE = 'step_up_not_possible';
    public const UNAVAILABLE = 'step_up_unavailable';

    /**
     * @param string $errorCode Stable error code (one of the constants).
     * @param int $status HTTP status.
     * @param string $message Plain message without any secret.
     * @param int|null $retryAfter Seconds until a new attempt may succeed, sent as `Retry-After`.
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The action needs a valid step-up grant and none was presented (403).
     *
     * @return self
     */
    public static function required(): self
    {
        return new self(self::REQUIRED, 403, 'Confirm it is you before revoking sessions.');
    }

    /**
     * A wrong password, a wrong or expired code, or an unknown confirmation (422, one wording for all of them).
     *
     * @return self
     */
    public static function failed(): self
    {
        return new self(self::FAILED, 422, 'The confirmation did not succeed. Check what you entered and try again.');
    }

    /**
     * Too many failed attempts: the confirmation of this account is locked (429).
     *
     * @param int $retryAfter Seconds until the lock ends.
     * @return self
     */
    public static function locked(int $retryAfter): self
    {
        return new self(self::LOCKED, 429, 'Too many failed attempts. Try again later.', max(1, $retryAfter));
    }

    /**
     * The hourly limit of e-mail codes is reached (429).
     *
     * @param int $retryAfter Seconds until a code may be sent again.
     * @return self
     */
    public static function emailLimit(int $retryAfter): self
    {
        return new self(self::EMAIL_LIMIT, 429, 'Too many codes were sent. Try again later.', max(1, $retryAfter));
    }

    /**
     * The account has a password and the request did not carry one (422; not counted as a failure).
     *
     * @return self
     */
    public static function passwordRequired(): self
    {
        return new self(self::PASSWORD_REQUIRED, 422, 'Enter your password.');
    }

    /**
     * The account cannot confirm (no password and no e-mail address, a second factor the account cannot provide, or a
     * request without a personal access token) (422).
     *
     * @return self
     */
    public static function notPossible(): self
    {
        return new self(self::NOT_POSSIBLE, 422, 'This account cannot confirm from here. Sign in again or ask an administrator.');
    }

    /**
     * The cache that holds counters, locks and grants failed: fail closed (503, no grant).
     *
     * @return self
     */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 503, 'The confirmation is unavailable right now. Try again in a few minutes.');
    }

    /**
     * Expected outcome, not an application error: keep it out of the error log (the audit events record it).
     *
     * @return bool
     */
    public function report(): bool
    {
        return true;
    }

    /**
     * Render the error envelope.
     *
     * @return JsonResponse
     */
    public function render(): JsonResponse
    {
        $headers = $this->retryAfter !== null ? ['Retry-After' => (string) $this->retryAfter] : [];
        $body = ['message' => $this->getMessage(), 'error_code' => $this->errorCode, 'status' => 'error'];
        if ($this->retryAfter !== null) {
            $body['retry_after'] = $this->retryAfter;
        }

        return response()->json($body, $this->status, $headers);
    }
}
