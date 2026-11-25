<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Jev;

use InvalidArgumentException;

/**
 * What a JEV judge is asked: `criteria` (trusted, written by the workflow author), `content` (untrusted, judged),
 * optional trusted `context`, the `lane` it runs for (log / receipt key) and the `mode` (`shadow` or `active`).
 *
 * @package Cms\Core\Services\Ai\Jev
 * @since   2026-11-24
 */
final class JevRequest
{
    public const MODES = ['shadow', 'active'];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $criteria,
        public readonly mixed $content,
        public readonly array $context = [],
        public readonly string $lane = 'workflow',
        public readonly string $mode = 'active',
    ) {
        if (trim($criteria) === '') {
            throw new InvalidArgumentException('JEV criteria are required.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown JEV mode [{$mode}].");
        }
    }
}
