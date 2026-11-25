<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Workflows\Nodes\NodeRegistry;

/**
 * Step types a workflow step row may store in `workflow_steps.step_type`.
 *
 * Storable = every node type of NodeRegistry (each runner key of `core.workflow.step_runners` and every registered
 * module node) plus the 13 legacy values of the original enum
 * (kept so old rows such as `script` stay valid and keep failing with a clear "no runner" message instead of
 * being rewritten to something that runs).
 *
 * @package Cms\Core\Workflows\Runtime
 * @since   2026-11-23
 */
final class StepTypeCatalog
{
    /** Values of the original `step_type` enum (2025_06_08 migration). */
    public const LEGACY = [
        'event', 'job', 'call_workflow', 'decision', 'webhook', 'ai_action', 'script', 'set_variable',
        'delay', 'schedule_at', 'approval', 'loop', 'parallel',
    ];

    /** Column length of `workflow_steps.step_type`. */
    public const MAX_LENGTH = 64;

    /**
     * @return list<string>
     */
    public static function storable(): array
    {
        $types = array_filter(
            NodeRegistry::current()->types(),
            static fn (string $type): bool => $type !== '' && strlen($type) <= self::MAX_LENGTH
        );

        return array_values(array_unique(array_merge(self::LEGACY, $types)));
    }

    public static function isStorable(string $type): bool
    {
        return in_array($type, self::storable(), true);
    }
}
