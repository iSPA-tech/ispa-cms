<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use InvalidArgumentException;

/**
 * One hierarchy node granted to a subject, with how far it reaches and where the grant came from.
 */
final readonly class HeldNode
{
    /**
     * Grant sources a held node may come from.
     */
    public const SOURCES = ['user', 'role'];

    /**
     * @param int $nodeId Held node id in the hierarchy.
     * @param Expansion $expansion How far the grant reaches from the node.
     * @param string $source 'user' for a direct grant, 'role' for a grant inherited from a role.
     * @param int $sourceId Id of the user or role that holds the node.
     *
     * @throws InvalidArgumentException When the source is not 'user' or 'role'.
     */
    public function __construct(
        public int $nodeId,
        public Expansion $expansion,
        public string $source,
        public int $sourceId,
    ) {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown held node source [{$source}].");
        }
    }
}
