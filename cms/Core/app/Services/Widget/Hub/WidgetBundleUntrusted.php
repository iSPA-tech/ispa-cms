<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use RuntimeException;
use Throwable;

/**
 * A widget bundle that must not be installed: bad envelope, unknown or revoked key, broken signature, non-canonical
 * or malformed payload, or an asset whose bytes do not match its sha256.
 */
final class WidgetBundleUntrusted extends RuntimeException
{
    public const ERROR_CODE = 'WIDGET_BUNDLE_UNTRUSTED';

    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(self::ERROR_CODE . ': ' . $message, 0, $previous);
    }
}
