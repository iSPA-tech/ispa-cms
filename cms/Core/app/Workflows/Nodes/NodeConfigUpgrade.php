<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

/**
 * Moves one node type's step_config from version `$from` to `$from + 1`.
 *
 * Called by NodeDefinition::upgrade() (through StepConfigUpgrader) for every version step a stored config is behind.
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-23
 */
interface NodeConfigUpgrade
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function upgrade(int $from, array $config): array;
}
