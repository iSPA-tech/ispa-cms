<?php

declare(strict_types=1);

namespace Cms\Core\Services\Agentic;

use RuntimeException;

/**
 * Refusal or failure of an ephemeral tool execution. `reason` is a stable, safe code; the message never carries
 * tool input, tool output or a tool exception message.
 */
final class EphemeralAgentToolException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
