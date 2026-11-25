<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Credentials;

use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The site's workflow credential store. Workflow configs hold `$bind:<name>` only; runners call resolveBinding() at
 * run time and get the decrypted payload, which they must never put in a step result or log (use redact()).
 *
 * @package Cms\Core\Services\Workflow\Credentials
 * @since   2026-11-24
 */
class WorkflowCredentialStore
{
    public const BIND_PREFIX = '$bind:';

    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    public const MASK = '[REDACTED]';

    /**
     * The credential name of a `$bind:<name>` value, or null when the value is not a binding.
     */
    public static function bindingName(mixed $value): ?string
    {
        if (!is_string($value) || !str_starts_with(trim($value), self::BIND_PREFIX)) {
            return null;
        }

        $name = trim(substr(trim($value), strlen(self::BIND_PREFIX)));

        return preg_match(self::NAME_PATTERN, $name) ? $name : null;
    }

    /**
     * Resolve a `$bind:<name>` config value to the payload of a credential of the expected type.
     *
     * @return array<string, mixed>
     *
     * @throws WorkflowCredentialException
     */
    public function resolveBinding(mixed $configValue, string $expectedType): array
    {
        $name = self::bindingName($configValue);
        if ($name === null) {
            throw new WorkflowCredentialException("A {$expectedType} credential is required: set it as \$bind:<name>.");
        }

        return $this->resolve($name, $expectedType);
    }

    /**
     * The decrypted payload of the named credential. Refuses a missing credential and a credential of another type.
     *
     * @return array<string, mixed>
     *
     * @throws WorkflowCredentialException
     */
    public function resolve(string $name, string $expectedType): array
    {
        $credential = WorkflowCredential::query()->where('name', $name)->first();
        if ($credential === null) {
            throw new WorkflowCredentialException("Credential {$name} does not exist.");
        }
        if ($credential->type !== $expectedType) {
            throw new WorkflowCredentialException("Credential {$name} is not a {$expectedType} credential.");
        }

        $payload = $credential->payload;
        if (!is_array($payload)) {
            throw new WorkflowCredentialException("Credential {$name} cannot be read.");
        }

        WorkflowCredential::query()->whereKey($credential->getKey())->update(['last_used_at' => now()]);

        return $payload;
    }

    /**
     * Record that a run step used a credential (roadmap B6): the name only, appended to `credentials_used` in the
     * `input_data` of that step's execution row (the executor never writes `input_data` of a graph step, so the entry
     * survives the step's own save). Does nothing outside a run (instance id 0, e.g. a package dry run) and never
     * fails the step.
     */
    public function recordUse(int $instanceId, string $stepCode, string $name): void
    {
        if ($instanceId <= 0 || $name === '') {
            return;
        }

        try {
            $row = WorkflowInstanceStep::query()
                ->where('instance_id', $instanceId)
                ->whereIn('step_id', WorkflowStep::withTrashed()->where('step_code', $stepCode)->select('id'))
                ->orderByDesc('id')
                ->first();
            if ($row === null) {
                return;
            }

            $input = is_array($row->input_data) ? $row->input_data : [];
            $used = array_values(array_unique(array_merge((array) ($input['credentials_used'] ?? []), [$name])));
            $input['credentials_used'] = $used;
            WorkflowInstanceStep::query()->whereKey($row->getKey())->update(['input_data' => json_encode($input, JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Active workflow projects whose live graph binds the named credential: the published version when versions exist
     * (`workflow_projects.published_version_id` → `workflow_versions.graph`), else the draft steps.
     *
     * @return list<WorkflowProject>
     */
    public function activeProjectsUsing(string $name): array
    {
        $projects = WorkflowProject::query()->where('is_active', true)->get();
        $versioned = Schema::hasTable('workflow_versions') && Schema::hasColumn((new WorkflowProject())->getTable(), 'published_version_id');

        $using = [];
        foreach ($projects as $project) {
            $versionId = $versioned ? $project->getAttribute('published_version_id') : null;
            if ($versionId) {
                $graph = json_decode((string) DB::table('workflow_versions')->where('id', $versionId)->value('graph'), true);
                $configs = array_map(static fn ($step) => is_array($step) ? ($step['step_config'] ?? []) : [], (array) ($graph['steps'] ?? []));
            } else {
                $configs = WorkflowStep::query()->where('project_id', $project->getKey())->pluck('step_config')
                    ->map(static fn ($config) => is_string($config) ? json_decode($config, true) : $config)->all();
            }

            foreach ($configs as $config) {
                if (self::bindsName($config, $name)) {
                    $using[] = $project;
                    break;
                }
            }
        }

        return $using;
    }

    /**
     * Whether a config (any depth) holds `$bind:<name>`.
     */
    public static function bindsName(mixed $config, string $name): bool
    {
        if (is_string($config)) {
            return self::bindingName($config) === $name;
        }
        if (is_array($config)) {
            foreach ($config as $value) {
                if (self::bindsName($value, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replace every secret value of the given payloads, wherever it appears inside $data (strings, nested arrays,
     * keys untouched), with [REDACTED]. Used on step results, logs and error messages.
     *
     * @param  list<array<string, mixed>> $payloads
     */
    public static function redact(mixed $data, array $payloads): mixed
    {
        $needles = self::secretValues($payloads);
        if ($needles === []) {
            return $data;
        }

        return self::replace($data, $needles);
    }

    /**
     * @param  list<array<string, mixed>> $payloads
     * @return list<string>
     */
    public static function secretValues(array $payloads): array
    {
        $needles = [];
        foreach ($payloads as $payload) {
            foreach ((array) $payload as $field => $value) {
                if (in_array($field, WorkflowCredential::PLAIN_FIELDS, true) || !is_scalar($value)) {
                    continue;
                }
                $value = (string) $value;
                if (strlen($value) >= 4) {
                    array_push($needles, ...self::encodedForms($value));
                }
            }
            // Basic auth sends base64("user:password"); an echoing endpoint returns that form.
            $username = ((array) $payload)['username'] ?? null;
            $password = ((array) $payload)['password'] ?? null;
            if (is_scalar($username) && is_scalar($password) && strlen((string) $password) >= 4) {
                $needles[] = base64_encode($username . ':' . $password);
            }
        }

        $needles = array_values(array_unique($needles));
        usort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $needles;
    }

    /**
     * A secret as it may come back: raw, URL-encoded and JSON-escaped (with and without escaped slashes and unicode).
     *
     * @return list<string>
     */
    private static function encodedForms(string $value): array
    {
        $forms = [$value, rawurlencode($value)];
        foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $json = json_encode($value, $flags);
            if (is_string($json)) {
                $forms[] = substr($json, 1, -1);
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * The hosts an HTTP credential may be sent to (normalized HttpHostAllowlist entries); empty when none are set.
     *
     * @param  array<string, mixed> $payload
     * @return list<string>
     */
    public static function allowedHosts(array $payload): array
    {
        $raw = $payload['allowed_hosts'] ?? null;
        if (!is_scalar($raw)) {
            return [];
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $normalized = HttpHostAllowlist::normalizeEntry($entry);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param list<string> $needles
     */
    private static function replace(mixed $data, array $needles): mixed
    {
        if (is_string($data)) {
            return str_replace($needles, self::MASK, $data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::replace($value, $needles);
            }
        }

        return $data;
    }

    /**
     * The admin view of a credential: never any payload value, only which fields are set.
     *
     * @return array<string, mixed>
     */
    public function describe(WorkflowCredential $credential): array
    {
        return [
            'uuid' => $credential->uuid,
            'name' => $credential->name,
            'type' => $credential->type,
            'description' => $credential->description,
            'fields' => array_keys(WorkflowCredential::FIELDS[$credential->type] ?? []),
            'fields_set' => $credential->fieldsSet(),
            // Not secret: the admin form shows the hosts so they can be changed.
            'allowed_hosts' => array_key_exists('allowed_hosts', WorkflowCredential::FIELDS[$credential->type] ?? [])
                ? implode(', ', self::allowedHosts(is_array($credential->payload) ? $credential->payload : []))
                : null,
            'binding' => self::BIND_PREFIX . $credential->name,
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'last_tested_at' => $credential->last_tested_at?->toIso8601String(),
            'last_test_ok' => $credential->last_test_ok,
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Create a credential. Required payload fields must be filled.
     *
     * @param array<string, mixed> $data name, type, description, payload
     *
     * @throws ValidationException
     */
    public function create(array $data, ?int $userId): WorkflowCredential
    {
        $type = (string) ($data['type'] ?? '');
        $payload = $this->cleanPayload($type, (array) ($data['payload'] ?? []));
        $this->assertComplete($type, $payload);

        return WorkflowCredential::query()->create([
            'name' => (string) $data['name'],
            'type' => $type,
            'description' => $data['description'] ?? null,
            'payload' => $payload,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Update a credential. A payload field left empty keeps its stored value, so a rename never needs the secret
     * again. The type never changes.
     *
     * @param array<string, mixed> $data name, description, payload
     *
     * @throws ValidationException
     */
    public function update(WorkflowCredential $credential, array $data, ?int $userId): WorkflowCredential
    {
        $stored = is_array($credential->payload) ? $credential->payload : [];
        $incoming = $this->cleanPayload($credential->type, (array) ($data['payload'] ?? []));
        $payload = array_merge($stored, array_filter($incoming, static fn ($v): bool => $v !== null && $v !== ''));
        $this->assertComplete($credential->type, $payload);

        $credential->fill([
            'name' => array_key_exists('name', $data) ? (string) $data['name'] : $credential->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $credential->description,
            'payload' => $payload,
            'updated_by' => $userId,
        ]);
        if ($incoming !== []) {
            $credential->last_test_ok = null;
            $credential->last_tested_at = null;
        }
        $credential->save();

        return $credential;
    }

    /**
     * Keep only the fields of the type, as trimmed strings.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, string|null>
     */
    private function cleanPayload(string $type, array $payload): array
    {
        $clean = [];
        foreach (array_keys(WorkflowCredential::FIELDS[$type] ?? []) as $field) {
            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];
                $clean[$field] = is_scalar($value) ? trim((string) $value) : null;
            }
        }
        if (($clean['allowed_hosts'] ?? '') !== '' && $clean['allowed_hosts'] !== null) {
            $clean['allowed_hosts'] = $this->normalizeHosts($clean['allowed_hosts']);
        }

        return $clean;
    }

    /**
     * Normalize a typed list of hosts (commas, spaces or new lines between entries) to "a, b, c". Every entry must be
     * a valid host entry (no bare wildcard, no private address).
     *
     * @throws ValidationException
     */
    private function normalizeHosts(string $typed): string
    {
        $entries = preg_split('/[\s,]+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];
        foreach ($entries as $entry) {
            $host = HttpHostAllowlist::normalizeEntry($entry);
            if ($host === null) {
                throw ValidationException::withMessages(['payload.allowed_hosts' => [__('text.workflow_credential.error.hosts_invalid', ['host' => $entry])]]);
            }
            $normalized[] = $host;
        }

        return implode(', ', array_values(array_unique($normalized)));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    private function assertComplete(string $type, array $payload): void
    {
        $errors = [];
        foreach (WorkflowCredential::FIELDS[$type] ?? [] as $field => $required) {
            if ($required && (($payload[$field] ?? '') === '' || $payload[$field] === null)) {
                $errors["payload.{$field}"] = [__('text.workflow_credential.error.field_required')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
