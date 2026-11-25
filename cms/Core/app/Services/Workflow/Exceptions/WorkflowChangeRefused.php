<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

use RuntimeException;

/**
 * A change-control action on a waiting workflow version was refused (guardrails B3): the author tried to approve their
 * own change (403 `author_cannot_approve`), someone without the right tried to reject or cancel (403
 * `not_allowed`), or the version is no longer waiting (422 `not_pending`).
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowChangeRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function authorCannotApprove(): self
    {
        return new self('author_cannot_approve', 403, 'The person who made a change can not approve it; another publisher must.');
    }

    public static function notAllowed(): self
    {
        return new self('not_allowed', 403, 'You can not do this for this change.');
    }

    public static function notPending(): self
    {
        return new self('not_pending', 422, 'This version is not waiting for a decision any more.');
    }
}
