<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

/**
 * Resolves a host name to its IPv4 and IPv6 addresses for SafeHttpFetcher. An IP literal resolves to itself.
 * Bound in the container so tests can answer without a network.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
class DnsResolver
{
    /**
     * @return list<string> Addresses, empty when the name does not resolve.
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
