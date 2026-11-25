<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use Cms\Core\Workflows\Nodes\NodeRegistry;

/**
 * The manifest of a workflow package, derived from its workflow row and the node catalog (never hand-written; the
 * inspector recomputes it and refuses a package whose manifest differs).
 *
 * ```
 * { node_types: { <type>: <config version> }, modules: [..], permissions: [..], safety_classes: [..],
 *   steps: [{ step_code, step_type, safety: [..], approval_default }], triggers: [..],
 *   outbound_hosts: [..], credential_kinds: [..], ai_task_keys: [..], agent_tools: [..],
 *   bindings: [{ name, kind, credential_type?, steps: [..] }] }
 * ```
 *
 * Outbound hosts are the literal hosts of `url` fields (`http_request`, `rss_read`, `sitemap_read`, `media_import`);
 * a URL built by an expression is listed as `(expression)`. AI task keys are the `task_key` of `ai_*` / `jev_review`
 * steps, or the `workflow_<type>` default.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
final class WorkflowPackageManifest
{
    private const URL_TYPES = ['http_request', 'rss_read', 'sitemap_read', 'media_import'];

    /**
     * @param array<string, mixed>       $row      WorkflowsSection row (bindings already extracted)
     * @param list<array<string, mixed>> $bindings
     * @return array<string, mixed>
     */
    public function derive(array $row, array $bindings): array
    {
        $registry = NodeRegistry::current();
        $nodeTypes = [];
        $modules = [];
        $permissions = [];
        $safety = [];
        $steps = [];
        $triggers = [];
        $hosts = [];
        $credentialKinds = [];
        $taskKeys = [];
        $tools = [];

        foreach ((array) ($row['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $type = (string) ($step['step_type'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            $definition = $registry->get($type);
            $version = is_int($config['_v'] ?? null) ? $config['_v'] : 1;
            $nodeTypes[$type] = max($nodeTypes[$type] ?? 1, $version);

            $stepSafety = $definition?->safetyFor($config) ?? ['unknown'];
            $safety = array_merge($safety, $stepSafety);
            $steps[] = [
                'step_code' => (string) ($step['step_code'] ?? ''),
                'step_type' => $type,
                'safety' => $stepSafety,
                'approval_default' => $definition?->approvalDefault,
            ];

            $modules[] = $definition?->module !== null ? $this->moduleName((string) $definition->module) : 'Core';
            if ($definition?->permission !== null) {
                $permissions[] = $definition->permission;
            }
            if (is_string($config['reviewer_permission'] ?? null) && $config['reviewer_permission'] !== '') {
                $permissions[] = $config['reviewer_permission'];
            }
            if (str_starts_with($type, 'trigger_')) {
                $triggers[] = $type;
            }
            // An app event owned by a module (`Cms\<Module>\...`) only fires where that module runs.
            if ($type === 'trigger_event' && is_string($config['event'] ?? null)
                && preg_match('/^\\\\?Cms\\\\([A-Za-z0-9]+)\\\\/', $config['event'], $m) === 1) {
                $modules[] = $this->moduleName($m[1]);
            }
            foreach ($definition?->bindings ?? [] as $kind) {
                $credentialKinds[] = (string) $kind;
            }
            if (str_starts_with($type, 'ai_') && !in_array($type, ['ai_action', 'ai_reasoning'], true) || $type === 'jev_review') {
                $taskKeys[] = is_string($config['task_key'] ?? null) && $config['task_key'] !== '' ? $config['task_key'] : 'workflow_' . $type;
            }
            if ($type === 'ai_action' && is_string($config['toolName'] ?? null) && $config['toolName'] !== '') {
                $tools[] = $config['toolName'];
            }
            if (in_array($type, self::URL_TYPES, true) && is_string($config['url'] ?? null) && trim($config['url']) !== '') {
                $hosts[] = $this->host($config['url']);
            }
        }

        foreach ($bindings as $binding) {
            if (($binding['kind'] ?? null) === 'credential' && is_string($binding['credential_type'] ?? null)) {
                $credentialKinds[] = $binding['credential_type'];
            }
        }

        ksort($nodeTypes);

        return [
            'node_types' => $nodeTypes,
            'modules' => $this->sorted($modules),
            'permissions' => $this->sorted($permissions),
            'safety_classes' => $this->sorted($safety),
            'steps' => $steps,
            'triggers' => $this->sorted($triggers),
            'outbound_hosts' => $this->sorted($hosts),
            'credential_kinds' => $this->sorted($credentialKinds),
            'ai_task_keys' => $this->sorted($taskKeys),
            'agent_tools' => $this->sorted($tools),
            'bindings' => array_values($bindings),
        ];
    }

    /**
     * Host of a literal URL (bare or quoted as an expression literal), or `(expression)`.
     */
    private function host(string $url): string
    {
        $url = trim($url);
        if (preg_match('/^([\'"])(.*)\1$/s', $url, $m) === 1) {
            $url = $m[2];
        }
        if (preg_match('#^https?://#i', $url) !== 1 || str_contains($url, '{{')) {
            return '(expression)';
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : '(expression)';
    }

    /**
     * Module folder name of a node type prefix (`content` → `Content`, `push_notification` → `PushNotification`).
     */
    private function moduleName(string $prefix): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $prefix)));
        foreach ((array) glob(base_path('cms/*'), GLOB_ONLYDIR) as $dir) {
            if (strcasecmp(basename((string) $dir), $studly) === 0) {
                return basename((string) $dir);
            }
        }

        return $studly;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn ($v): bool => is_string($v) && $v !== '')));
        sort($values);

        return $values;
    }
}
