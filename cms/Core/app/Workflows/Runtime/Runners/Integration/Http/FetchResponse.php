<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

/**
 * What SafeHttpFetcher got back. The body is untrusted outside data (tainted).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
final class FetchResponse
{
    /**
     * @param array<string, string> $headers Lower-case names; Set-Cookie is dropped.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
        public readonly int $redirects,
        public readonly int $attempts = 1,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Media type without parameters, lower case (`text/html`). */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
