<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use RuntimeException;

/**
 * A messaging node could not do its work (bad config, provider refused). The message is shown in the run history
 * after redaction.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
class IntegrationFailure extends RuntimeException
{
}
