<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;

/**
 * SafeHttpFetcher refused or could not finish a request. `reason` is a stable code: scheme, url, host_not_allowed,
 * port_not_allowed, dns_failed, blocked_address, too_many_redirects, redirect_invalid, too_large, connection,
 * method.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
class FetchRefused extends IntegrationFailure
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
