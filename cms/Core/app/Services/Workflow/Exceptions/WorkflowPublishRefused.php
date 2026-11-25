<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

use RuntimeException;

/**
 * The publish policy refused a workflow graph (B4). `problems` lists `{code, message, step_code?}`.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowPublishRefused extends RuntimeException
{
    public const CODE = 'publish_refused';

    /**
     * @param list<array{code: string, message: string, step_code?: string}> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode('; ', array_column($problems, 'message')) ?: 'The workflow can not be published.');
    }
}
