<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Services\Workflow\Mail\TriggerSubjectResolver;
use Cms\Core\Workflows\Runtime\Runners\Integration\EmailSendPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Checks a whole workflow graph against the node catalog before it is saved or imported, and reports every problem
 * at once (`[{step_code, code, message}]`):
 * - `config_version_unsupported`: `step_config._v` is not an int or is newer than this site's node definition;
 * - `config_invalid`: the config does not match the node's `config_schema` (reserved keys `node_type`, `_v`,
 *   `_upgrade_log` are ignored; an empty config only has to satisfy `required`);
 * - `permission_denied`: the saving user lacks the node's `permission` (skipped when no user is known, e.g. a CLI
 *   package import);
 * - `taint_needs_approval`: a node whose safety is `internal_write` or `publish` (and is not marked `taint_sink`
 *   false) reads data that came from a taint source (a node with `taint_output`) along a path with no approval node
 *   in between. An `email_send` step that meets owner e-mail policy B (EmailSendPolicy::isManaged: a managed template,
 *   recipient = the trigger's own subject or staff, no free text) needs no approval for data that came from the run's
 *   trigger (its filled values are escaped and untrusted); data from any other taint source (AI output, fetched pages)
 *   still needs one;
 * - `email_template_unavailable`: an `email_send` step in template mode names a key that has no template in group
 *   `workflow` and no declared default (`core.mail_templates.workflow.defaults`); `config_invalid` when the key is
 *   missing or not a plain name;
 * - `email_recipient_unavailable`: an `email_send` step sends to the trigger's own subject but no `trigger_event` step
 *   of the graph starts on an app event that declares one (webhook and model-event data never count).
 *
 * Unknown step types are not reported here (callers already refuse them with their own message); a type with no
 * definition (legacy values such as `script`) is skipped.
 *
 * Taint lint: a node "reads" a value when a string in its config mentions `vars.<name>` or `steps.<code>`; a mention of
 * `trigger.*` / `root.*` (what started the run) reads every trigger step's data. A node "writes" `vars.<name>` through
 * `storeAs`, `to`, an assignment `key` or a loop `itemVar` (a value that is only that path), and always
 * `steps.<its code>`. Taint flows through any non-approval node that reads a tainted value. Without edges the steps are
 * taken in list order. An approval stops a path only along an edge it takes once approved (`approved`, or a plain edge,
 * which the executor never follows for `rejected` / `expired` / `changes_requested`); its other outputs (`expired`,
 * `rejected`, `default`, ...) pass the data on unreviewed (review 2026-11-24 findings 4, 5).
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-23
 */
class NodeConfigValidator
{
    public const RESERVED_KEYS = ['node_type', '_v', '_upgrade_log'];

    private const WRITE_KEYS = ['storeAs', 'to', 'key', 'itemVar'];

    /** Token read by a `trigger.*` / `root.*` mention; resolved to the trigger steps' tokens. */
    private const TRIGGER_TOKEN = 't:';

    /** Edge handles the executor follows out of an approval only when it was approved (null = plain edge). */
    public const APPROVED_HANDLES = [null, 'approved', 'output', 'main'];

    public function __construct(
        protected ?NodeRegistry $registry = null,
        protected JsonSchemaShapeValidator $validator = new JsonSchemaShapeValidator(),
    ) {
        $this->registry ??= NodeRegistry::current();
    }

    /**
     * @param list<array{step_code: string, step_type: string, step_config: array<string, mixed>|null}> $steps
     * @param list<array{from: string, to: string, handle?: string|null}> $edges `handle` = the output the edge leaves
     *                                                                        from (null or missing = plain edge)
     * @return list<array{step_code: string, code: string, message: string}>
     */
    public function validate(array $steps, array $edges, ?Authenticatable $user, bool $checkPermissions = true): array
    {
        $problems = [];

        foreach ($steps as $step) {
            $code = (string) $step['step_code'];
            $definition = $this->registry->get((string) $step['step_type']);
            if ($definition === null) {
                continue;
            }

            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];

            $version = $config['_v'] ?? 1;
            if (!is_int($version) || $version < 1) {
                $problems[] = $this->problem($code, 'config_version_unsupported', "{$code}: _v must be a positive whole number.");
            } elseif ($version > $definition->version) {
                $problems[] = $this->problem($code, 'config_version_unsupported',
                    "{$code}: config version {$version} is newer than this site supports ({$definition->type} v{$definition->version}).");
            }

            foreach (self::schemaErrors($definition->configSchema, self::stripReserved($config), $this->validator) as $error) {
                $problems[] = $this->problem($code, 'config_invalid', "{$code}: {$error}");
            }

            // An app event only starts a workflow where it is allowlisted (`trigger_events` / `events`); a site without
            // it (its module off, or older code) would keep a workflow that never starts.
            if ($definition->type === 'trigger_event' && is_string($config['event'] ?? null) && trim($config['event']) !== ''
                && !app(\Cms\Core\Workflows\Security\WorkflowCallableGuard::class)->allowsTriggerEvent($config['event'])) {
                $problems[] = $this->problem($code, 'event_unavailable',
                    "{$code}: the event {$config['event']} cannot start workflows on this site (not on the trigger_events allowlist; its module may be off).");
            }

            if ($checkPermissions && $user !== null && $definition->permission !== null
                && !Gate::forUser($user)->allows($definition->permission)) {
                $problems[] = $this->problem($code, 'permission_denied',
                    "{$code}: {$definition->type} needs permission {$definition->permission}.");
            }
        }

        return array_merge($problems, $this->emailProblems($steps), $this->taintProblems($steps, $edges));
    }

    /**
     * Template-mode checks of `email_send` steps (policy B).
     *
     * @param list<array{step_code: string, step_type: string, step_config: array<string, mixed>|null}> $steps
     * @return list<array{step_code: string, code: string, message: string}>
     */
    protected function emailProblems(array $steps): array
    {
        $problems = [];
        $subjectEvent = null;
        foreach ($steps as $step) {
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            if ((string) $step['step_type'] === 'trigger_event' && $subjectEvent === null
                && app(TriggerSubjectResolver::class)->declared(is_string($config['event'] ?? null) ? $config['event'] : null) !== null) {
                $subjectEvent = (string) $config['event'];
            }
        }

        foreach ($steps as $step) {
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            if ((string) $step['step_type'] !== 'email_send' || EmailSendPolicy::mode($config) !== EmailSendPolicy::MODE_TEMPLATE) {
                continue;
            }
            $code = (string) $step['step_code'];
            $key = EmailSendPolicy::templateKey($config);
            if ($key === null) {
                $problems[] = $this->problem($code, 'config_invalid', "{$code}: template_key must name an e-mail template (letters, digits, dot, dash or underscore).");
            } elseif (!app(ManagedEmailTemplates::class)->knows($key)) {
                $problems[] = $this->problem($code, 'email_template_unavailable', "{$code}: there is no e-mail template {$key} in group workflow on this site.");
            }
            if (EmailSendPolicy::recipient($config) === EmailSendPolicy::RECIPIENT_TRIGGER_SUBJECT && $subjectEvent === null) {
                $problems[] = $this->problem($code, 'email_recipient_unavailable',
                    "{$code}: sends to the trigger's own person, but the workflow does not start on an app event that names one (account sign-up, contact message).");
            }
        }

        return $problems;
    }

    /**
     * Whether an edge leaving an approval node on this output handle runs only after the item was approved.
     */
    public static function passesApproval(?string $handle): bool
    {
        return in_array($handle === null ? null : trim($handle), self::APPROVED_HANDLES, true);
    }

    /**
     * The output handle of a stored connection condition, read the way the executor reads it
     * (WorkflowGraphExecutor::connectionHandle()); null = plain edge.
     */
    public static function edgeHandle(mixed $condition): ?string
    {
        $condition = is_array($condition) ? $condition : [];
        $type = $condition['condition_type'] ?? null;
        $value = $condition['condition_value'] ?? null;
        $source = $condition['source_handle'] ?? null;
        $label = $condition['condition'] ?? null;

        if ((!is_string($type) || trim($type) === '') && is_string($source) && trim($source) !== '') {
            $type = 'equals';
            $value = is_string($value) && trim($value) !== '' ? $value : $source;
        }
        if ((!is_string($type) || trim($type) === '') && is_string($label) && trim($label) !== '') {
            $type = 'equals';
            $value = $label;
        }
        $handle = is_string($type) && trim($type) === 'equals' && is_string($value) && trim($value) !== '' ? trim($value) : null;

        return in_array($handle, ['output', 'main'], true) ? null : $handle;
    }

    /**
     * Full `vars.*` paths a config writes (`storeAs`, `to`, assignment `key`, loop `itemVar` / `indexVar`).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function writePaths(array $config): array
    {
        $paths = [];
        array_walk_recursive($config, static function (mixed $value, string|int $key) use (&$paths): void {
            if (in_array($key, [...self::WRITE_KEYS, 'indexVar'], true) && is_string($value)
                && preg_match('/^\s*(vars\.[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)\s*$/', $value, $m)) {
                $paths[] = $m[1];
            }
        });

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function stripReserved(array $config): array
    {
        return array_diff_key($config, array_flip(self::RESERVED_KEYS));
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function schemaErrors(array $schema, array $config, ?JsonSchemaShapeValidator $validator = null): array
    {
        if ($config === []) {
            // `[]` is how an empty JSON object decodes; only `required` applies to it.
            $errors = [];
            foreach ((array) ($schema['required'] ?? []) as $key) {
                $errors[] = sprintf('$.%s is required.', $key);
            }

            return $errors;
        }

        return array_values(($validator ?? new JsonSchemaShapeValidator())->validate($schema, $config));
    }

    /**
     * @param list<array{step_code: string, step_type: string, step_config: array<string, mixed>|null}> $steps
     * @param list<array{from: string, to: string}> $edges
     * @return list<array{step_code: string, code: string, message: string}>
     */
    protected function taintProblems(array $steps, array $edges): array
    {
        $approvalTypes = $this->registry->approvalTypes();
        // `trigger.*` / `root.*` carry what started the run: data of whichever trigger step started it.
        $triggerTokens = [];
        foreach ($steps as $step) {
            if (str_starts_with((string) $step['step_type'], 'trigger_')) {
                $triggerTokens[] = 's:' . $step['step_code'];
            }
        }
        $nodes = [];
        foreach ($steps as $step) {
            $code = (string) $step['step_code'];
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            $reads = [];
            $writes = ['s:' . $code];
            $this->scan(self::stripReserved($config), null, $reads, $writes);
            if (in_array(self::TRIGGER_TOKEN, $reads, true)) {
                $reads = [...array_diff($reads, [self::TRIGGER_TOKEN]), ...$triggerTokens];
            }
            $nodes[$code] = [
                'definition' => $this->registry->get((string) $step['step_type']),
                'approval' => in_array((string) $step['step_type'], $approvalTypes, true),
                'reads' => array_unique($reads),
                'writes' => array_unique($writes),
                'managed_mail' => (string) $step['step_type'] === 'email_send' && EmailSendPolicy::isManaged($config),
            ];
        }

        $sources = array_keys(array_filter($nodes, static fn (array $n): bool => !$n['approval'] && ($n['definition']?->taintOutput ?? false)));
        if ($sources === []) {
            return [];
        }

        // token => [origin source code => true]
        $tainted = [];
        foreach ($sources as $source) {
            foreach ($nodes[$source]['writes'] as $token) {
                $tainted[$token][$source] = true;
            }
        }

        for ($changed = true, $guard = 0; $changed && $guard < 1000; $guard++) {
            $changed = false;
            foreach ($nodes as $node) {
                if ($node['approval']) {
                    continue;
                }
                $origins = [];
                foreach ($node['reads'] as $token) {
                    $origins += $tainted[$token] ?? [];
                }
                foreach ($node['writes'] as $token) {
                    $before = count($tainted[$token] ?? []);
                    $tainted[$token] = ($tainted[$token] ?? []) + $origins;
                    $changed = $changed || count($tainted[$token]) !== $before;
                }
            }
        }

        $next = [];
        if ($edges === []) {
            $codes = array_keys($nodes);
            for ($i = 1; $i < count($codes); $i++) {
                $next[$codes[$i - 1]][] = ['to' => $codes[$i], 'handle' => null];
            }
        } else {
            foreach ($edges as $edge) {
                $handle = $edge['handle'] ?? null;
                $next[(string) $edge['from']][] = ['to' => (string) $edge['to'], 'handle' => is_string($handle) ? $handle : null];
            }
        }

        $reachable = [];
        foreach ($sources as $source) {
            $reachable[$source] = $this->reachWithoutApproval($source, $next, $nodes);
        }

        $problems = [];
        foreach ($nodes as $code => $node) {
            if ($node['approval'] || !($node['definition']?->isGuardedSink() ?? false)) {
                continue;
            }
            // Policy B: a managed e-mail takes the trigger's own data escaped; other untrusted data still needs review.
            $managedMail = $node['managed_mail'];

            foreach ($node['reads'] as $token) {
                foreach (array_keys($tainted[$token] ?? []) as $origin) {
                    if ($managedMail && str_starts_with((string) ($nodes[$origin]['definition']?->type ?? ''), 'trigger_')) {
                        continue;
                    }
                    if ($origin !== $code && isset($reachable[$origin][$code])) {
                        $problems[] = $this->problem((string) $code, 'taint_needs_approval',
                            "{$code}: reads untrusted data from {$origin}; add an approval step between them.");
                        continue 3;
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Steps reachable from $source without passing an approval on an edge it takes once approved.
     *
     * @param array<string, list<array{to: string, handle: string|null}>> $next
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, true>
     */
    private function reachWithoutApproval(string $source, array $next, array $nodes): array
    {
        $seen = [];
        $queue = array_column($next[$source] ?? [], 'to');
        while ($queue !== []) {
            $code = array_shift($queue);
            if (isset($seen[$code]) || !isset($nodes[$code])) {
                continue;
            }
            $seen[$code] = true;
            foreach ($next[$code] ?? [] as $edge) {
                if (!$nodes[$code]['approval'] || !self::passesApproval($edge['handle'])) {
                    $queue[] = $edge['to'];
                }
            }
        }

        return $seen;
    }

    /**
     * @param list<string> $reads
     * @param list<string> $writes
     */
    private function scan(mixed $value, string|int|null $key, array &$reads, array &$writes): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->scan($child, $childKey, $reads, $writes);
            }

            return;
        }

        if (!is_string($value)) {
            return;
        }

        if (in_array($key, self::WRITE_KEYS, true) && preg_match('/^\s*vars\.([A-Za-z_][A-Za-z0-9_]*)[A-Za-z0-9_.]*\s*$/', $value, $m)) {
            $writes[] = 'v:' . $m[1];

            return;
        }

        // `trigger.x`, `trigger['x']`, `root.x`, or a bare `trigger` / `root` expression.
        if (preg_match('/(?<![\w.$])(?:trigger|root)\s*(?:[.\[]|}})|^\s*(?:trigger|root)\s*$/', $value)) {
            $reads[] = self::TRIGGER_TOKEN;
        }

        if (preg_match_all('/(?<![\w.])vars\.([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches)) {
            foreach ($matches[1] as $name) {
                $reads[] = 'v:' . $name;
            }
        }
        if (preg_match_all('/(?<![\w.])steps\.([A-Za-z0-9_\-]+)/', $value, $matches)) {
            foreach ($matches[1] as $name) {
                $reads[] = 's:' . $name;
            }
        }
    }

    /**
     * @return array{step_code: string, code: string, message: string}
     */
    private function problem(string $stepCode, string $code, string $message): array
    {
        return ['step_code' => $stepCode, 'code' => $code, 'message' => $message];
    }
}
