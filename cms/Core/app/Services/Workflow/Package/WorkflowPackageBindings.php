<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use App\Models\User;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Nodes\NodeRegistry;

/**
 * Site-local references of a workflow package (`$bind:<name>` placeholders).
 *
 * Export (extract): every reference that only means something on the source site becomes a named binding:
 * - credential values, already `$bind:<credential name>` in a step config: kept as they are (kind `credential`, with
 *   the credential type the node field declares through `x-secret`);
 * - `agentProfileSlug` of `ai_action` / `ai_reasoning` → `$bind:agent_profile_<n>` (kind `agent_profile`);
 * - `project` of `execute_workflow` (slug or numeric id) → `$bind:workflow_<n>` (kind `workflow`);
 * - every UUID inside any step config string (approval `assignees`, `notify` users, a fixed post or ticket uuid) →
 *   `$bind:user_<n>` when it is a user of this site, else `$bind:record_<n>` (kind `user` / `record`).
 * The same source value always gets the same binding name. The source values never enter the package.
 *
 * Install (resolve): the caller maps each binding name to a local value. Credentials default to a credential of the
 * same name and must exist with a matching type; agent profiles, users and workflows must exist on this site; records
 * must be UUIDs. A credential binding renames the whole `$bind:` value; every other binding replaces its
 * `$bind:<name>` token wherever it appears in a string. Nothing is installed while a binding is unresolved.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
final class WorkflowPackageBindings
{
    public const KINDS = ['credential', 'agent_profile', 'workflow', 'user', 'record'];

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private const UUID_EXACT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** `$bind:<name>` token inside a string (names as WorkflowCredentialStore::NAME_PATTERN). */
    private const TOKEN = '/\$bind:([a-z0-9][a-z0-9_.-]{0,63})/';

    /** Credential types a node field's `x-secret` kind accepts. */
    public const CREDENTIAL_TYPES = ['http_auth' => ['http_header', 'http_basic']];

    /**
     * Replace site-local references in a WorkflowsSection row by bindings.
     *
     * @param array<string, mixed> $row
     * @return array{row: array<string, mixed>, bindings: list<array<string, mixed>>}
     */
    public function extract(array $row): array
    {
        $registry = NodeRegistry::current();
        $bindings = [];
        $byValue = [];
        $counters = [];

        $name = function (string $kind, string $value) use (&$bindings, &$byValue, &$counters): string {
            $key = $kind . "\0" . strtolower($value);
            if (isset($byValue[$key])) {
                return $byValue[$key];
            }
            $counters[$kind] = ($counters[$kind] ?? 0) + 1;
            $bindName = $kind . '_' . $counters[$kind];
            $byValue[$key] = $bindName;
            $bindings[$bindName] = ['name' => $bindName, 'kind' => $kind, 'steps' => []];

            return $bindName;
        };

        foreach ((array) ($row['steps'] ?? []) as $index => $step) {
            if (!is_array($step) || !is_array($step['step_config'] ?? null)) {
                continue;
            }

            $type = (string) ($step['step_type'] ?? '');
            $code = (string) ($step['step_code'] ?? '');
            $config = $step['step_config'];
            $properties = (array) ($registry->get($type)?->configSchema['properties'] ?? []);
            $used = [];

            foreach ($config as $key => $value) {
                // Credentials: kept by name, typed by the field's x-secret kind.
                $credential = WorkflowCredentialStore::bindingName($value);
                if ($credential !== null) {
                    $bindings[$credential] ??= ['name' => $credential, 'kind' => 'credential', 'steps' => []];
                    $secretKind = $properties[$key]['x-secret'] ?? null;
                    if (is_string($secretKind)) {
                        $bindings[$credential]['credential_type'] = $secretKind;
                    }
                    $used[] = $credential;
                    continue;
                }

                if ($key === 'agentProfileSlug' && in_array($type, ['ai_action', 'ai_reasoning'], true)
                    && is_string($value) && trim($value) !== '') {
                    $config[$key] = WorkflowCredentialStore::BIND_PREFIX . ($used[] = $name('agent_profile', trim($value)));
                    continue;
                }

                if ($key === 'project' && $type === 'execute_workflow' && (is_int($value) || (is_string($value) && trim($value) !== ''))) {
                    $slug = is_int($value) || ctype_digit((string) $value)
                        ? (string) WorkflowProject::query()->whereKey((int) $value)->value('slug')
                        : trim((string) $value);
                    $config[$key] = WorkflowCredentialStore::BIND_PREFIX . ($used[] = $name('workflow', $slug !== '' ? $slug : (string) $value));
                }
            }

            // Every UUID left anywhere in the config is a reference to a record of this site.
            array_walk_recursive($config, function (mixed &$value) use ($name, &$used): void {
                if (!is_string($value) || preg_match(self::UUID, $value) !== 1) {
                    return;
                }
                $value = (string) preg_replace_callback(self::UUID, function (array $m) use ($name, &$used): string {
                    $kind = User::query()->where('uuid', $m[0])->exists() ? 'user' : 'record';

                    return WorkflowCredentialStore::BIND_PREFIX . ($used[] = $name($kind, strtolower($m[0])));
                }, $value);
            });

            foreach (array_unique($used) as $bindName) {
                $bindings[$bindName]['steps'][] = $code;
            }

            $row['steps'][$index]['step_config'] = $config;
        }

        ksort($bindings);

        return ['row' => $row, 'bindings' => array_values(array_map(static function (array $b): array {
            $b['steps'] = array_values(array_unique($b['steps']));

            return $b;
        }, $bindings))];
    }

    /**
     * Check the caller's values for a package's bindings. Returns the resolved map (binding name → local value) and the
     * problems (`binding_missing`, `binding_invalid`); install refuses while any problem is left.
     *
     * @param list<array<string, mixed>> $declared The manifest's `bindings`
     * @param array<string, mixed>       $values   Caller's map, binding name → value
     * @return array{resolved: array<string, string>, problems: list<array{code: string, binding: string, message: string}>}
     */
    public function resolve(array $declared, array $values): array
    {
        $resolved = [];
        $problems = [];

        foreach ($declared as $binding) {
            $bindName = (string) ($binding['name'] ?? '');
            $kind = (string) ($binding['kind'] ?? '');
            $value = $values[$bindName] ?? ($kind === 'credential' ? $bindName : null);
            $value = is_string($value) ? trim($value) : null;

            if ($value === null || $value === '') {
                $problems[] = ['code' => 'binding_missing', 'binding' => $bindName, 'message' => "Binding {$bindName} ({$kind}) needs a value on this site."];
                continue;
            }

            $error = $this->checkValue($kind, $value, $binding['credential_type'] ?? null);
            if ($error !== null) {
                $problems[] = ['code' => 'binding_invalid', 'binding' => $bindName, 'message' => "Binding {$bindName}: {$error}"];
                continue;
            }

            $resolved[$bindName] = $value;
        }

        return ['resolved' => $resolved, 'problems' => $problems];
    }

    /**
     * Put resolved values back into a workflow row.
     *
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $declared
     * @param array<string, string>      $resolved
     * @return array<string, mixed>
     */
    public function apply(array $row, array $declared, array $resolved): array
    {
        $kinds = [];
        foreach ($declared as $binding) {
            $kinds[(string) ($binding['name'] ?? '')] = (string) ($binding['kind'] ?? '');
        }

        foreach ((array) ($row['steps'] ?? []) as $index => $step) {
            if (!is_array($step) || !is_array($step['step_config'] ?? null)) {
                continue;
            }
            $config = $step['step_config'];
            array_walk_recursive($config, function (mixed &$value) use ($kinds, $resolved): void {
                if (!is_string($value) || !str_contains($value, WorkflowCredentialStore::BIND_PREFIX)) {
                    return;
                }

                $credential = WorkflowCredentialStore::bindingName($value);
                if ($credential !== null && ($kinds[$credential] ?? null) === 'credential') {
                    $value = WorkflowCredentialStore::BIND_PREFIX . ($resolved[$credential] ?? $credential);

                    return;
                }

                $value = (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($kinds, $resolved): string {
                    $kind = $kinds[$m[1]] ?? null;

                    return $kind !== null && $kind !== 'credential' && isset($resolved[$m[1]]) ? $resolved[$m[1]] : $m[0];
                }, $value);
            });
            $row['steps'][$index]['step_config'] = $config;
        }

        return $row;
    }

    /**
     * `$bind:` names used in a row that are not credentials and not declared: a forged manifest must not hide one.
     *
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $declared
     * @return list<string>
     */
    public function undeclared(array $row, array $declared): array
    {
        $names = array_flip(array_map(static fn (array $b): string => (string) ($b['name'] ?? ''), $declared));
        $found = [];
        array_walk_recursive($row, static function (mixed $value) use (&$found): void {
            if (is_string($value) && preg_match_all(self::TOKEN, $value, $m)) {
                array_push($found, ...$m[1]);
            }
        });

        return array_values(array_unique(array_filter($found, static fn (string $n): bool => !isset($names[$n]))));
    }

    private function checkValue(string $kind, string $value, mixed $credentialType): ?string
    {
        switch ($kind) {
            case 'credential':
                if (preg_match(WorkflowCredentialStore::NAME_PATTERN, $value) !== 1) {
                    return 'not a credential name.';
                }
                $type = WorkflowCredential::query()->where('name', $value)->value('type');
                if ($type === null) {
                    return "credential {$value} does not exist on this site.";
                }
                $accepted = is_string($credentialType) ? (self::CREDENTIAL_TYPES[$credentialType] ?? [$credentialType]) : null;

                return $accepted !== null && !in_array($type, $accepted, true) ? "credential {$value} is a {$type} credential." : null;
            case 'agent_profile':
                return AgentProfile::query()->where('slug', $value)->exists() ? null : 'no agent profile with this slug.';
            case 'workflow':
                return WorkflowProject::query()->where('slug', $value)->exists() ? null : 'no workflow with this slug.';
            case 'user':
                return preg_match(self::UUID_EXACT, $value) === 1 && User::query()->where('uuid', $value)->exists()
                    ? null : 'no user with this uuid.';
            case 'record':
                return preg_match(self::UUID_EXACT, $value) === 1 ? null : 'must be a uuid.';
            default:
                return 'unknown binding kind.';
        }
    }
}
