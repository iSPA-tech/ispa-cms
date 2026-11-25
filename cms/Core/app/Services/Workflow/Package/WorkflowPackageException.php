<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use RuntimeException;

/**
 * A workflow package was refused; `report` is the inspector's report (problems, binding and slug problems) when known.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
class WorkflowPackageException extends RuntimeException
{
    /**
     * @param array<string, mixed> $report
     */
    public function __construct(string $message, public readonly array $report = [])
    {
        parent::__construct($message);
    }
}
