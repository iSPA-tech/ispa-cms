<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Cms\Core\Services\StarterPacks\PackContentScanner;
use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Human\ApprovalAssignment;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Validates a workflow package against this site and previews its install; writes nothing.
 *
 * Blocking `problems` (all reported at once, messages never echo secret values):
 * - envelope: format, version, keys, size, checksum (`bad_envelope`, `checksum_mismatch`);
 * - the config-package checks on the workflow row (PackageValidator: shape, numeric ids, SecretGuard) and the content
 *   scanner (PackContentScanner: tokens, keys, ciphertext, personal emails/phones in configs or the sample);
 * - the manifest must equal the one derived from the workflow (`manifest_mismatch`) and declare every `$bind:` name
 *   (`binding_undeclared`); every node type must exist here in a version at least the package's
 *   (`unknown_node_type`, `node_version_unsupported`);
 * - the same server-side node check as a local save: WorkflowsSection prepare rules (unknown step type, blueprint
 *   callable allowlist, secrets, graph shape), NodeConfigValidator (schema, `_v`, the user's node permissions,
 *   taint/approval lint `taint_needs_approval`), the model allowlist of `retrieve`/`create_object`, and — once the
 *   bindings are resolved — ApprovalAssignment (assignees must be users here).
 * Install blockers kept apart: `binding_problems` (unresolved or invalid bindings) and `slug_problems` (the target slug
 * exists, also as a deleted workflow). `warnings`: outbound hosts not on this site's allowlist.
 * Rows carry what the admin page names without parsing the English message: `step_code` when one step is at fault,
 * `node_type` on node type problems, `host` on allowlist warnings.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
class WorkflowPackageInspector
{
    public function __construct(
        protected WorkflowPackageBindings $bindings = new WorkflowPackageBindings(),
        protected WorkflowPackageManifest $manifests = new WorkflowPackageManifest(),
        protected WorkflowPackageDryRun $dryRun = new WorkflowPackageDryRun(),
    ) {
    }

    /**
     * @param array<string, mixed>      $package
     * @param array<string, mixed>      $bindingValues binding name → local value
     * @param array<string, mixed>|null $sample        overrides the package's `sample`
     * @return array<string, mixed>
     */
    public function inspect(array $package, ?User $user, array $bindingValues = [], ?string $asSlug = null, ?array $sample = null, bool $dryRun = true): array
    {
        $problems = [];
        $this->checkEnvelope($package, $problems);

        $row = is_array($package['workflow'] ?? null) ? $package['workflow'] : [];
        $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
        $declared = array_values(array_filter((array) ($manifest['bindings'] ?? []), 'is_array'));
        $sourceSlug = is_string($row['slug'] ?? null) ? trim($row['slug']) : '';
        $targetSlug = $asSlug !== null && trim($asSlug) !== '' ? trim($asSlug) : $sourceSlug;

        if ($row === [] || $sourceSlug === '') {
            $problems[] = $this->problem('bad_envelope', 'workflow must be a workflow row with a slug.');
        } else {
            if (array_key_exists('is_active', $row)) {
                $problems[] = $this->problem('bad_envelope', 'workflow.is_active is not allowed: a package never decides whether it runs.');
            }
            $this->checkConfigPackageRules($row, $problems);
            $this->checkContent($package, $problems);
            $this->checkManifest($row, $manifest, $declared, $problems);
            $this->checkNodeTypes($manifest, $problems);
            $this->checkGraph($row, $user, $problems);
        }

        $resolution = $this->bindings->resolve($declared, $bindingValues);
        $bindingProblems = $resolution['problems'];
        if ($bindingProblems === [] && $row !== []) {
            // With every binding resolved, approval assignees are checked as on a local save.
            $resolvedRow = $this->bindings->apply($row, $declared, $resolution['resolved']);
            foreach ((array) ($resolvedRow['steps'] ?? []) as $step) {
                if (is_array($step) && in_array($step['step_type'] ?? null, ['approval', 'ai_action'], true)) {
                    $stepCode = (string) ($step['step_code'] ?? '');
                    foreach (ApprovalAssignment::configProblems($stepCode, (array) ($step['step_config'] ?? [])) as $p) {
                        $bindingProblems[] = ['code' => $p['code'], 'binding' => null, 'message' => $p['message'], 'step_code' => $stepCode];
                    }
                }
            }
        }

        $slugProblems = [];
        if ($targetSlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/', $targetSlug) !== 1) {
            $slugProblems[] = $this->problem('slug_invalid', 'The workflow slug must be lowercase letters, digits, - or _.');
        } elseif (WorkflowProject::withTrashed()->where('slug', $targetSlug)->exists()) {
            $slugProblems[] = $this->problem('slug_exists', "A workflow with slug {$targetSlug} already exists here; install it as a copy under a new slug.");
        }

        $samplePayload = $sample ?? (is_array($package['sample'] ?? null) ? $package['sample'] : null);
        $report = null;
        if ($dryRun && $row !== [] && is_array($samplePayload)) {
            try {
                $report = $this->dryRun->run($row, $samplePayload);
            } catch (Throwable $e) {
                $report = ['completed' => false, 'steps' => [], 'vars' => [], 'error' => $e->getMessage()];
            }
        }

        return [
            'valid' => $problems === [],
            'installable' => $problems === [] && $bindingProblems === [] && $slugProblems === [],
            'source_slug' => $sourceSlug,
            'target_slug' => $targetSlug,
            'name' => $row['name'] ?? null,
            'manifest' => $manifest,
            'problems' => $problems,
            'binding_problems' => $bindingProblems,
            'slug_problems' => $slugProblems,
            'warnings' => $this->warnings($manifest),
            'bindings' => array_map(static fn (array $b): array => $b + ['resolved' => isset($resolution['resolved'][$b['name'] ?? ''])], $declared),
            'dry_run' => $report,
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @param list<array<string, mixed>> $problems
     */
    private function checkEnvelope(array $package, array &$problems): void
    {
        try {
            $size = strlen(WorkflowPackageFormat::encode($package));
        } catch (JsonException) {
            $problems[] = $this->problem('bad_envelope', 'The package cannot be encoded as JSON.');

            return;
        }
        if ($size > WorkflowPackageFormat::MAX_BYTES) {
            $problems[] = $this->problem('bad_envelope', "The package is larger than " . WorkflowPackageFormat::MAX_BYTES . ' bytes.');
        }
        if (($package['format'] ?? null) !== WorkflowPackageFormat::FORMAT) {
            $problems[] = $this->problem('bad_envelope', 'format must be "' . WorkflowPackageFormat::FORMAT . '".');
        }
        if (!is_int($package['version'] ?? null) || $package['version'] < 1 || $package['version'] > WorkflowPackageFormat::VERSION) {
            $problems[] = $this->problem('bad_envelope', 'Unsupported version; this site reads version ' . WorkflowPackageFormat::VERSION . '.');
        }
        foreach (array_keys($package) as $key) {
            if (!in_array($key, WorkflowPackageFormat::TOP_LEVEL_KEYS, true)) {
                $problems[] = $this->problem('bad_envelope', "Unknown top-level key \"{$key}\".");
            }
        }
        foreach (WorkflowPackageFormat::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $package)) {
                $problems[] = $this->problem('bad_envelope', "Missing key \"{$key}\".");
            }
        }
        if (isset($package['sample']) && (!is_array($package['sample']) || !is_array($package['sample']['root'] ?? []))) {
            $problems[] = $this->problem('bad_envelope', 'sample must be an object with a root object.');
        }
        try {
            if (($package['checksum'] ?? null) !== WorkflowPackageFormat::checksum($package)) {
                $problems[] = $this->problem('checksum_mismatch', 'The checksum does not match the package content (changed after export).');
            }
        } catch (JsonException) {
            // Reported above.
        }
    }

    /**
     * PackageValidator on a config package that carries only this workflow row: section shape, numeric ids, secrets.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $problems
     */
    private function checkConfigPackageRules(array $row, array &$problems): void
    {
        $sections = ['workflows' => [$row + ['is_active' => false]]];
        try {
            $result = (new PackageValidator())->validate([
                'format' => PackageFormat::FORMAT,
                'format_version' => PackageFormat::VERSION,
                'created_at' => now()->toIso8601String(),
                'sections' => $sections,
                'checksum' => PackageFormat::checksum($sections),
            ]);
        } catch (JsonException) {
            $problems[] = $this->problem('bad_envelope', 'The workflow cannot be encoded as JSON.');

            return;
        }

        foreach ($result->issues() as $error) {
            $key = (string) ($error['key'] ?? 'invalid');
            $code = match (true) {
                str_ends_with($key, '.numeric_id') => 'numeric_id',
                str_ends_with($key, '.secret_found') => 'secret_found',
                default => 'workflow_invalid',
            };
            $problems[] = $this->problem($code, str_replace('sections.workflows.0', 'workflow', (string) ($error['message'] ?? $key)));
        }
    }

    /**
     * @param array<string, mixed> $package
     * @param list<array<string, mixed>> $problems
     */
    private function checkContent(array $package, array &$problems): void
    {
        $scanned = array_intersect_key($package, array_flip(['workflow', 'sample']));
        // A `$bind:<name>` credential reference names an entry of the receiving site's credential store, never a value.
        array_walk_recursive($scanned, static function (mixed &$value): void {
            if (is_string($value) && preg_match('/^\$bind:[a-z0-9][a-z0-9_.-]{0,63}$/', $value) === 1) {
                $value = '${binding}';
            }
        });
        $json = json_encode($scanned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ((new PackContentScanner())->scanString((string) $json, 'package.json') as $finding) {
            $rule = (string) ($finding['rule'] ?? 'content');
            $problems[] = $this->problem($rule === 'secret_value' || $rule === 'token' || $rule === 'private_key' || $rule === 'ciphertext' ? 'secret_found' : 'personal_data',
                "The package content matches rule {$rule} (line {$finding['line']}); remove it before sharing.");
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $declared
     * @param list<array<string, mixed>> $problems
     */
    private function checkManifest(array $row, array $manifest, array $declared, array &$problems): void
    {
        foreach ($declared as $binding) {
            if (!is_string($binding['name'] ?? null) || !in_array($binding['kind'] ?? null, WorkflowPackageBindings::KINDS, true)) {
                $problems[] = $this->problem('manifest_mismatch', 'A binding has no name or an unknown kind.');
            }
        }
        foreach ($this->bindings->undeclared($row, $declared) as $name) {
            $problems[] = $this->problem('binding_undeclared', "The workflow uses \$bind:{$name}, which the manifest does not declare.");
        }

        $derived = $this->manifests->derive($row, $declared);
        try {
            if (PackageFormat::canonicalJson($derived) !== PackageFormat::canonicalJson($manifest)) {
                $problems[] = $this->problem('manifest_mismatch', 'The manifest does not match the workflow (it is derived from the graph and cannot be edited).');
            }
        } catch (JsonException) {
            $problems[] = $this->problem('manifest_mismatch', 'The manifest cannot be encoded.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $problems
     */
    private function checkNodeTypes(array $manifest, array &$problems): void
    {
        $registry = NodeRegistry::current();
        foreach ((array) ($manifest['node_types'] ?? []) as $type => $version) {
            $definition = $registry->get((string) $type);
            if ($definition === null) {
                $problems[] = $this->problem('unknown_node_type', "Node type {$type} is not available on this site (missing module or newer package).", null, ['node_type' => (string) $type]);
            } elseif (is_int($version) && $version > $definition->version) {
                $problems[] = $this->problem('node_version_unsupported', "Node type {$type} needs config version {$version}; this site has {$definition->version}.", null, ['node_type' => (string) $type]);
            }
        }
    }

    /**
     * The server-side check of a local save, on the workflow under a probe slug (so an existing slug does not hide it).
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $problems
     */
    private function checkGraph(array $row, ?User $user, array &$problems): void
    {
        $steps = array_values(array_filter((array) ($row['steps'] ?? []), 'is_array'));
        $nodeProblems = (new NodeConfigValidator())->validate(
            array_map(static fn (array $s): array => ['step_code' => (string) ($s['step_code'] ?? ''), 'step_type' => (string) ($s['step_type'] ?? ''), 'step_config' => is_array($s['step_config'] ?? null) ? $s['step_config'] : []], $steps),
            array_map(static fn (array $c): array => ['from' => (string) ($c['from'] ?? ''), 'to' => (string) ($c['to'] ?? ''), 'handle' => NodeConfigValidator::edgeHandle($c['condition'] ?? null)], array_values(array_filter((array) ($row['connections'] ?? []), 'is_array'))),
            $user,
        );
        foreach ($nodeProblems as $p) {
            $problems[] = $this->problem((string) $p['code'], (string) $p['message'], (string) $p['step_code']);
        }

        $guard = app(WorkflowCallableGuard::class);
        foreach ($steps as $step) {
            $model = $step['step_config']['model'] ?? null;
            if (in_array($step['step_type'] ?? null, ['retrieve', 'create_object'], true) && is_string($model) && !$guard->allowsModel($model)) {
                $problems[] = $this->problem('model_not_allowed', "{$step['step_code']}: {$step['step_type']}: model not allowed", (string) $step['step_code']);
            }
        }

        $probe = $row;
        $probe['slug'] = 'package-probe-' . Str::lower(Str::random(12));
        unset($probe['is_active']);
        $item = (new WorkflowsSection())->diff([$probe], new DiffContext($user?->getKey() !== null ? (int) $user->getKey() : null))->items[0] ?? null;
        $reason = is_array($item) && ($item['action'] ?? null) === 'blocked' ? (string) $item['reason'] : null;
        if ($reason !== null && $reason !== implode('; ', array_column($nodeProblems, 'message'))) {
            $code = match (true) {
                str_contains($reason, 'unknown step_type') => 'unknown_node_type',
                str_contains($reason, 'class not allowed') => 'callable_not_allowed',
                $reason === 'contains_secret' => 'secret_found',
                default => 'workflow_refused',
            };
            $problems[] = $this->problem($code, $reason);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function warnings(array $manifest): array
    {
        $warnings = [];
        $allowlist = app(HttpHostAllowlist::class);
        foreach ((array) ($manifest['outbound_hosts'] ?? []) as $host) {
            if ($host !== '(expression)' && is_string($host) && !$allowlist->knowsHost($host)) {
                $warnings[] = $this->problem('host_not_allowlisted', "Outbound host {$host} is not on this site's allowlist; its steps fail until a Webmaster adds it.", null, ['host' => $host]);
            }
        }
        if (in_array('(expression)', (array) ($manifest['outbound_hosts'] ?? []), true)) {
            $warnings[] = $this->problem('host_from_expression', 'A step builds its URL from data; only allowlisted hosts are reachable at run time.');
        }

        return $warnings;
    }

    /**
     * @param array<string, string> $fields extra named fields (`node_type`, `host`)
     * @return array<string, string>
     */
    private function problem(string $code, string $message, ?string $stepCode = null, array $fields = []): array
    {
        return ['code' => $code, 'message' => $message] + ($stepCode !== null && $stepCode !== '' ? ['step_code' => $stepCode] : []) + $fields;
    }
}
