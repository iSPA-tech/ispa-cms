<?php

declare(strict_types=1);

namespace App\Support\Modules\Exceptions;

use LogicException;

/**
 * Thrown when a caller tries to disable or delete the status of the Core
 * module. Core is always enabled; every site depends on it.
 */
class CoreModuleCannotBeDisabled extends LogicException
{
    /**
     * Build the exception for the attempted operation.
     *
     * @param string $operation Activator method that was called (e.g. "disable").
     */
    public function __construct(string $operation = 'disable')
    {
        parent::__construct(sprintf('The Core module is always enabled; "%s" is not allowed for it.', $operation));
    }
}
