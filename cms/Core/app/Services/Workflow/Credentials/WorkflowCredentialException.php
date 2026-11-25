<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Credentials;

use RuntimeException;

/**
 * A credential binding could not be resolved. Messages name the binding and type only, never a value.
 *
 * @package Cms\Core\Services\Workflow\Credentials
 * @since   2026-11-24
 */
class WorkflowCredentialException extends RuntimeException
{
}
