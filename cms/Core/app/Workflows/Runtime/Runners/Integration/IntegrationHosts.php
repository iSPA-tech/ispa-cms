<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

/**
 * Fixed outbound hosts of the messaging nodes. Not configurable on purpose: a node can only reach its own service.
 * Timeouts come from `core.workflow_nodes.messaging.settings`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
final class IntegrationHosts
{
    public const TELEGRAM = 'https://api.telegram.org';

    public const FACEBOOK = 'https://graph.facebook.com';

    public const OPENAI = 'https://api.openai.com';

    public static function facebookVersion(): string
    {
        $version = (string) config('core.workflow_nodes.messaging.settings.facebook_graph_version', 'v26.0');

        return preg_match('/^v\d{1,3}\.\d{1,2}$/', $version) ? $version : 'v26.0';
    }

    public static function timeout(): int
    {
        return max(3, min(120, (int) config('core.workflow_nodes.messaging.settings.timeout_seconds', 20)));
    }

    public static function connectTimeout(): int
    {
        return max(1, min(30, (int) config('core.workflow_nodes.messaging.settings.connect_timeout_seconds', 5)));
    }
}
