<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use RuntimeException;
use Throwable;

/**
 * A refused workflow library request: the hub cannot be reached, the template is unknown or hidden, its bundle is not
 * trusted, or it cannot be installed here. `errorCode` is stable for the admin page; `status` is the HTTP status the
 * admin API answers with; `report` carries the package inspector report when the install was refused by it.
 */
final class WorkflowHubException extends RuntimeException
{
    public const UNREACHABLE = 'WORKFLOW_HUB_UNREACHABLE';

    public const NOT_FOUND = 'WORKFLOW_HUB_NOT_FOUND';

    public const UNTRUSTED = 'WORKFLOW_BUNDLE_UNTRUSTED';

    public const NOT_AVAILABLE = 'WORKFLOW_TEMPLATE_NOT_AVAILABLE';

    public const NOT_INSTALLABLE = 'WORKFLOW_TEMPLATE_NOT_INSTALLABLE';

    /** The newest install is not modified and already has this version (or a newer one): nothing to update. */
    public const UP_TO_DATE = 'WORKFLOW_TEMPLATE_UP_TO_DATE';

    /** The install changed (edited or updated) while an update in place waited for it; asking again decides anew. */
    public const CHANGED = 'WORKFLOW_TEMPLATE_CHANGED';

    /**
     * @param array<string, mixed>|null $report
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422, public readonly ?array $report = null, ?Throwable $previous = null)
    {
        parent::__construct($errorCode . ': ' . $message, 0, $previous);
    }

    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(self::UNREACHABLE, 'the workflow library on the hub cannot be reached', 503, null, $previous);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'workflow template not found', 404);
    }

    public static function untrusted(string $reason, ?Throwable $previous = null): self
    {
        return new self(self::UNTRUSTED, $reason, 422, null, $previous);
    }
}
