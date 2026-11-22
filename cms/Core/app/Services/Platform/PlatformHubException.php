<?php

namespace Cms\Core\Services\Platform;

use RuntimeException;

/**
 * Raised when a signed call to the platform hub cannot be made or trusted.
 * Messages are fixed, generic strings and never carry the domain secret.
 */
final class PlatformHubException extends RuntimeException
{
    public const INSECURE_URL = 'insecure_url';

    public const NOT_CONFIGURED = 'not_configured';

    public const TRANSPORT = 'transport';

    public const HTTP_STATUS = 'http_status';

    public const REDIRECT = 'redirect';

    public const BAD_SIGNATURE = 'bad_signature';

    public const BAD_RESPONSE = 'bad_response';

    /**
     * Admin error code for a hub that answered but refused this site's signed credentials (its 401
     * PLATFORM_UNAUTHORIZED): the connection is not recognised, which is not a network outage.
     */
    public const SITE_NOT_RECOGNISED_CODE = 'MODULE_HUB_NOT_RECOGNISED';

    /**
     * @param  string  $reason  One of the reason constants.
     * @param  string  $message  Generic, secret-free description.
     * @param  int  $status  HTTP status when relevant, otherwise 0.
     */
    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Machine-readable failure reason (one of the class constants).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * HTTP status of the hub response, or 0 when no response was received.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * True when the hub answered 401: it does not recognise this website's connection (unknown, pending, suspended or
     * expired site, or a stale secret). A call without stored credentials fails earlier with NOT_CONFIGURED.
     */
    public function siteNotRecognised(): bool
    {
        return $this->reason === self::HTTP_STATUS && $this->status === 401;
    }
}
