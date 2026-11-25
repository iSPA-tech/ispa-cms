<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes\Attributes;

use Attribute;

/**
 * Marks a public service method as a workflow node a module contributes.
 *
 * The method signature is `(array $config, WorkflowContext $context): array`. ModuleNodeRunner validates the config
 * against `schema` (fields with `x-expr: true` are evaluated first), calls the method and stores the returned array
 * under the step's `storeAs` (`vars.*`). The service class must sit in a namespace listed in
 * `core.workflow_nodes.allowlisted_namespaces` and be registered with NodeRegistry::registerModuleService().
 *
 * @package Cms\Core\Workflows\Nodes\Attributes
 * @since   2026-11-23
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WorkflowNode
{
    /**
     * @param string               $type        `<module>.<name>`, e.g. `helpdesk.save_draft`
     * @param list<string>         $safety      read | internal_write | external_call | ai_spend | publish
     * @param string               $approval    required | on | off (publish is always required)
     * @param array<string, mixed> $schema      config JSON-schema subset
     * @param array<string, mixed> $defaults    default_config dropped by the palette
     * @param list<string>         $outputs     named output handles
     * @param list<string>         $bindings    credential binding kinds
     * @param class-string|null    $upgrader    NodeConfigUpgrade implementation for version > 1
     * @param bool                 $taintSink   false only for a draft-only write nobody outside sees: the taint lint
     *                                          then needs no approval before it (see NodeDefinition::$taintSink)
     * @param list<array<string, mixed>>|null $result Parts of the returned array the designer's data picker lists:
     *                                          `[{key, type, label, label_key?, item?, values?, values_from?}]`
     *                                          (NodeDefinition::$result; `values` = the known set the field holds)
     * @param string               $resultType  Type of the whole returned value (NodeDefinition::RESULT_TYPES)
     * @param string|null          $dynamic     Result expanded from the step config (NodeDefinition::DYNAMIC_KINDS)
     */
    public function __construct(
        public string $type,
        public int $version = 1,
        public string $group = 'module',
        public array $safety = ['read'],
        public string $approval = 'off',
        public ?string $permission = null,
        public bool $taint = false,
        public ?string $label = null,
        public ?string $description = null,
        public array $schema = ['type' => 'object'],
        public array $defaults = [],
        public array $outputs = ['main'],
        public array $bindings = [],
        public ?string $upgrader = null,
        public ?string $icon = null,
        public ?string $editor = null,
        public bool $taintSink = true,
        public ?array $result = null,
        public string $resultType = 'object',
        public ?string $dynamic = null,
    ) {
    }
}
