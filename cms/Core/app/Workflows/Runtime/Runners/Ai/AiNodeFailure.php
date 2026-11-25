<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Ai;

use RuntimeException;

/**
 * A clean AI node failure: the message is shown as the step error (no prompt or output body in it).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Ai
 * @since   2026-11-24
 */
final class AiNodeFailure extends RuntimeException
{
}
