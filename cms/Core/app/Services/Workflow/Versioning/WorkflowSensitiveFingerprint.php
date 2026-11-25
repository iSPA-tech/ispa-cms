<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Package\WorkflowPackageManifest;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval;
use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeRegistry;

/**
 * The sensitive projection of a workflow graph: only what changes how a
 * workflow reaches the outside world, spends money or writes data. Change control applies when this projection of a
 * new version differs from the published one in a way changes() reports.
 *
 * Summary:
 * ```
 * { triggers: { settings: {trigger_event, target_entity_type, schedule_cron, schedule_timezone, error_workflow_project_id},
 *               nodes: { <step_code>: {type, config} } },              // unattended trigger nodes only
 *   sensitive_steps: { <step_code>: {type, safety: [..]} },   // safety contains publish, external_call or ai_spend
 *   hosts: [..],                                               // literal outbound hosts, `(expression)` for built URLs
 *   credentials: { <step_code>: [<$bind name>, ..] },
 *   sends: { <step_code>: {type, target: {to|cc|bcc|reply_to|chat|audience|user_uuid|url|method|auth|credential|mode|
 *                                         template_key|recipient|recipient_permission|business_enabled: ..},
 *                          binds: {<config path>: <$bind name>}} },  // external_call steps: who / where / how
 *   writes: { <step_code>: {type, target, fields: [..]} },     // safety contains internal_write or publish
 *   approvals: { <step_code>: {mode, reversible_skip, expires_after?, jev?: {min_confidence, verdict_from, hold_if,
 *                              producer, writers}} },
 *                                                              // approval steps with a write/publish after their
 *                                                              // `approved` edge; `jev` only when the step sets
 *                                                              // one of the first three
 *   unguarded: [<step_code>, ..],                              // write/publish steps reachable without an approval
 *   projection: <int>,                                         // version of this projection
 *   budgets: {cost_per_run, runs_per_day} }
 * ```
 *
 * An approval guards only along an edge it takes once approved (`approved`, or a plain edge: the executor never
 * follows one for `rejected` / `expired` / `changes_requested`); its other outputs lead on unreviewed (review
 * 2026-11-24 finding 5). `jev.producer` lists the JEV judge steps whose stored verdict `verdict_from` reads and
 * `jev.writers` the other steps that write that path (finding 6). `sends` makes a changed recipient, URL text, method
 * or credential placement of an existing send a sensitive change (finding 13).
 *
 * Never part of it: step names, positions, prompts and other wording, assignees, SLA, rewrite settings. The hash covers
 * everything but `budgets` (lowering a cap is not sensitive; changes() reports a raised one).
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class WorkflowSensitiveFingerprint
{
    public const KINDS = [
        'trigger_changed', 'sensitive_step_added', 'sensitive_step_removed', 'host_added', 'credential_changed',
        'write_target_changed', 'approval_removed', 'approval_mode_changed', 'budget_raised',
    ];

    /** Safety classes that make a node itself sensitive. */
    private const SENSITIVE_SAFETY = ['publish', 'external_call', 'ai_spend'];

    /** Safety classes of a node that writes (its target is sensitive and an approval before it guards it). */
    private const WRITE_SAFETY = ['internal_write', 'publish'];

    /** Trigger node config keys that are wording, not behaviour. */
    private const TRIGGER_WORDING = ['label', 'title', 'description', 'note', 'name'];

    private const BIND_PREFIX = '$bind:';

    /** Bumped when the projection gains fields; a stored summary of an older projection is recomputed for comparison. */
    public const PROJECTION = 2;

    /** Config keys of a send that say who receives it, where it goes and with which login (never its wording). */
    private const SEND_TARGET_KEYS = ['to', 'cc', 'bcc', 'reply_to', 'chat', 'audience', 'user_uuid', 'url', 'method', 'auth', 'credential'];

    /**
     * Extra target keys of `email_send` (template mode, policy B 2026-11-25): what decides whether and what it sends,
     * including the business-mail switch `business_enabled` (flipping it either way is a sensitive change). They only
     * exist in version 2 configs, so stored summaries of older versions stay comparable.
     */
    private const EMAIL_TARGET_KEYS = ['mode', 'template_key', 'recipient', 'recipient_permission', 'business_enabled'];

    /**
     * @param array<string, mixed> $graph Canonical graph (WorkflowVersionSnapshot).
     * @return array{hash: string, summary: array<string, mixed>}
     */
    public static function of(array $graph): array
    {
        $summary = self::summary($graph);
        $hashed = $summary;
        unset($hashed['budgets']);

        return ['hash' => hash('sha256', (string) json_encode($hashed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 'summary' => $summary];
    }

    /**
     * Summary of a workflow with nothing in it (what a first publish is compared with).
     *
     * @return array<string, mixed>
     */
    public static function emptySummary(): array
    {
        return self::summary(['steps' => [], 'connections' => [], 'settings' => []]);
    }

    /**
     * The stored summary of a version; versions saved before change control (the v1 backfill) get it computed and
     * stored now.
     *
     * @return array<string, mixed>
     */
    public static function forVersion(WorkflowVersion $version): array
    {
        $stored = $version->sensitive_summary;
        if (is_array($stored) && $version->sensitive_fingerprint !== null && $stored !== []) {
            if ((int) ($stored['projection'] ?? 1) >= self::PROJECTION) {
                return $stored;
            }

            // Stored by an older projection (no `sends`, guards blind to output handles): compare a fresh projection
            // of the frozen graph, without rewriting the stored row.
            return self::of($version->graphData())['summary'];
        }

        $fingerprint = self::of($version->graphData());
        if ($version->exists) {
            // Not a frozen column: the projection is derived from the frozen graph.
            WorkflowVersion::query()->whereKey($version->getKey())->update([
                'sensitive_fingerprint' => $fingerprint['hash'],
                'sensitive_summary' => json_encode($fingerprint['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        $version->forceFill(['sensitive_fingerprint' => $fingerprint['hash'], 'sensitive_summary' => $fingerprint['summary']])->syncOriginal();

        return $fingerprint['summary'];
    }

    /**
     * What a new version changes in sensitive behaviour compared with $old ([] = nothing sensitive changed).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<array{kind: string, step_code?: string, detail: string}>
     */
    public static function changes(array $old, array $new): array
    {
        $changes = [];

        $oldSettings = (array) data_get($old, 'triggers.settings', []);
        foreach ((array) data_get($new, 'triggers.settings', []) as $key => $value) {
            if (($oldSettings[$key] ?? null) !== $value) {
                $changes[] = ['kind' => 'trigger_changed', 'detail' => (string) $key];
            }
        }
        $oldTriggers = (array) data_get($old, 'triggers.nodes', []);
        $newTriggers = (array) data_get($new, 'triggers.nodes', []);
        foreach ($newTriggers as $code => $node) {
            if (($oldTriggers[$code] ?? null) !== $node) {
                $changes[] = ['kind' => 'trigger_changed', 'step_code' => (string) $code, 'detail' => (string) ($node['type'] ?? '')];
            }
        }
        foreach (array_diff_key($oldTriggers, $newTriggers) as $code => $node) {
            $changes[] = ['kind' => 'trigger_changed', 'step_code' => (string) $code, 'detail' => 'removed ' . ($node['type'] ?? '')];
        }

        $oldSteps = (array) ($old['sensitive_steps'] ?? []);
        $newSteps = (array) ($new['sensitive_steps'] ?? []);
        foreach ($newSteps as $code => $step) {
            $before = $oldSteps[$code] ?? null;
            if ($before === null || ($before['type'] ?? null) !== ($step['type'] ?? null)
                || array_diff((array) ($step['safety'] ?? []), (array) ($before['safety'] ?? [])) !== []) {
                $changes[] = ['kind' => 'sensitive_step_added', 'step_code' => (string) $code, 'detail' => self::stepDetail($step)];
            }
        }
        foreach (array_diff_key($oldSteps, $newSteps) as $code => $step) {
            $changes[] = ['kind' => 'sensitive_step_removed', 'step_code' => (string) $code, 'detail' => self::stepDetail($step)];
        }

        foreach (array_diff((array) ($new['hosts'] ?? []), (array) ($old['hosts'] ?? [])) as $host) {
            $changes[] = ['kind' => 'host_added', 'detail' => (string) $host];
        }

        $oldCredentials = (array) ($old['credentials'] ?? []);
        foreach ((array) ($new['credentials'] ?? []) as $code => $names) {
            $added = array_diff((array) $names, (array) ($oldCredentials[$code] ?? []));
            if ($added !== []) {
                $changes[] = ['kind' => 'credential_changed', 'step_code' => (string) $code, 'detail' => implode(', ', $added)];
            }
        }

        $oldSends = (array) ($old['sends'] ?? []);
        foreach ((array) ($new['sends'] ?? []) as $code => $send) {
            $before = $oldSends[$code] ?? null;
            if (!is_array($before) || ($before['type'] ?? null) !== ($send['type'] ?? null)) {
                continue; // a new send step is reported as sensitive_step_added
            }
            $changes = array_merge($changes, self::sendChanges((string) $code, $before, (array) $send));
        }

        $oldWrites = (array) ($old['writes'] ?? []);
        foreach ((array) ($new['writes'] ?? []) as $code => $write) {
            if (($oldWrites[$code] ?? null) !== $write) {
                $changes[] = ['kind' => 'write_target_changed', 'step_code' => (string) $code, 'detail' => self::writeDetail($write)];
            }
        }

        $oldApprovals = (array) ($old['approvals'] ?? []);
        $newApprovals = (array) ($new['approvals'] ?? []);
        foreach (array_diff_key($oldApprovals, $newApprovals) as $code => $approval) {
            $changes[] = ['kind' => 'approval_removed', 'step_code' => (string) $code, 'detail' => 'approval step removed or no longer guards a write'];
        }
        foreach ($newApprovals as $code => $approval) {
            $before = $oldApprovals[$code] ?? null;
            if ($before === null) {
                continue;
            }
            $weaker = (($approval['mode'] ?? 'human') !== ($before['mode'] ?? 'human') && ($approval['mode'] ?? 'human') !== 'human')
                || (!($before['reversible_skip'] ?? true) && ($approval['reversible_skip'] ?? true));
            if ($weaker) {
                $changes[] = ['kind' => 'approval_mode_changed', 'step_code' => (string) $code, 'detail' => ($before['mode'] ?? 'human') . ' -> ' . ($approval['mode'] ?? 'human')];
            } elseif (($approval['expires_after'] ?? null) !== null && ($approval['expires_after'] ?? null) !== ($before['expires_after'] ?? null)) {
                // An expiry decides the item without a person; its output may lead anywhere.
                $changes[] = ['kind' => 'approval_mode_changed', 'step_code' => (string) $code, 'detail' => 'expires_after ' . ($before['expires_after'] ?? 'none') . ' -> ' . $approval['expires_after']];
            } elseif (($approval['mode'] ?? 'human') === 'jev' && ($detail = self::jevWeakened((array) ($before['jev'] ?? []), (array) ($approval['jev'] ?? []))) !== null) {
                $changes[] = ['kind' => 'approval_mode_changed', 'step_code' => (string) $code, 'detail' => $detail];
            }
        }
        foreach (array_diff((array) ($new['unguarded'] ?? []), (array) ($old['unguarded'] ?? [])) as $code) {
            // A write that existed guarded and now has a path around every approval (a bypass edge, a removed approval).
            if (isset($oldWrites[$code])) {
                $changes[] = ['kind' => 'approval_removed', 'step_code' => (string) $code, 'detail' => 'reachable without an approval'];
            }
        }

        foreach (['cost_per_run', 'runs_per_day'] as $key) {
            $before = data_get($old, 'budgets.' . $key);
            $after = data_get($new, 'budgets.' . $key);
            if (self::raised($before, $after)) {
                $changes[] = ['kind' => 'budget_raised', 'detail' => $key . ': ' . ($before ?? 'none') . ' -> ' . ($after ?? 'no limit')];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    private static function summary(array $graph): array
    {
        $registry = NodeRegistry::current();
        $manifest = new WorkflowPackageManifest();
        $approvalTypes = $registry->approvalTypes();
        $settings = is_array($graph['settings'] ?? null) ? $graph['settings'] : [];

        $steps = [];
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            if (is_array($step) && is_scalar($step['step_code'] ?? null) && (string) $step['step_code'] !== '') {
                $steps[(string) $step['step_code']] = $step;
            }
        }
        ksort($steps);

        $triggerNodes = [];
        $sensitive = [];
        $hosts = [];
        $credentials = [];
        $sends = [];
        $writes = [];
        $types = [];
        foreach ($steps as $code => $step) {
            $type = (string) ($step['step_type'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            $types[$code] = $type;
            $definition = $registry->get($type);
            $safety = $definition?->safetyFor($config) ?? [];
            sort($safety);

            // Manual and sub-workflow starts need a person or another (reviewed) workflow; the others run unattended.
            if (in_array($type, WorkflowTriggers::UNATTENDED, true)) {
                $triggerNodes[$code] = ['type' => $type, 'config' => self::canonical(array_diff_key($config, array_flip(self::TRIGGER_WORDING)))];
            }

            $sensitiveSafety = array_values(array_intersect($safety, self::SENSITIVE_SAFETY));
            if ($sensitiveSafety !== []) {
                $sensitive[$code] = ['type' => $type, 'safety' => $sensitiveSafety];
            }

            foreach ((array) ($manifest->derive(['steps' => [$step]], [])['outbound_hosts'] ?? []) as $host) {
                $hosts[] = (string) $host;
            }

            $names = self::bindNames($config);
            if ($names !== []) {
                $credentials[$code] = $names;
            }

            if (in_array('external_call', $safety, true) && !in_array($type, $approvalTypes, true)) {
                $sends[$code] = ['type' => $type, 'target' => self::sendTarget($config, $type), 'binds' => self::bindPlaces($config)];
            }

            if (array_intersect($safety, self::WRITE_SAFETY) !== [] && !in_array($type, $approvalTypes, true)) {
                $writes[$code] = ['type' => $type, 'target' => self::writeTarget($config), 'fields' => self::writeFields($config)];
            }
        }

        [$approvals, $unguarded] = self::guards($steps, $types, $writes, (array) ($graph['connections'] ?? []), $approvalTypes);

        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return [
            'triggers' => [
                'settings' => [
                    'trigger_event' => self::unlessDefault($settings['trigger_event'] ?? null, 'manual'),
                    'target_entity_type' => self::unlessDefault($settings['target_entity_type'] ?? null, 'none'),
                    'schedule_cron' => $settings['schedule_cron'] ?? null,
                    'schedule_timezone' => $settings['schedule_timezone'] ?? null,
                    'error_workflow_project_id' => $settings['error_workflow_project_id'] ?? null,
                ],
                'nodes' => $triggerNodes,
            ],
            'sensitive_steps' => $sensitive,
            'hosts' => $hosts,
            'credentials' => $credentials,
            'sends' => $sends,
            'writes' => $writes,
            'approvals' => $approvals,
            'unguarded' => $unguarded,
            'projection' => self::PROJECTION,
            'budgets' => [
                'cost_per_run' => is_numeric($settings['budget_max_cost_per_run'] ?? null) ? number_format((float) $settings['budget_max_cost_per_run'], 4, '.', '') : null,
                'runs_per_day' => is_numeric($settings['budget_max_runs_per_day'] ?? null) ? (int) $settings['budget_max_runs_per_day'] : null,
            ],
        ];
    }

    /**
     * Approval steps that guard a write (a write is reachable after their `approved` edge) and the writes reachable
     * from a start without passing any approval on an edge it takes once approved.
     *
     * @param array<string, array<string, mixed>> $steps
     * @param array<string, string>               $types
     * @param array<string, mixed>                $writes
     * @param array<int, mixed>                   $connections
     * @param list<string>                        $approvalTypes
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private static function guards(array $steps, array $types, array $writes, array $connections, array $approvalTypes): array
    {
        $isApproval = static fn (string $code): bool => in_array($types[$code] ?? '', $approvalTypes, true);
        // $next: every edge; $open: the edges a walk may take past an approval (all edges of other steps).
        $next = [];
        $open = [];
        $incoming = [];
        foreach ($connections as $edge) {
            $from = is_array($edge) ? ($edge['from'] ?? null) : null;
            $to = is_array($edge) ? ($edge['to'] ?? null) : null;
            if (is_scalar($from) && is_scalar($to) && isset($types[(string) $from], $types[(string) $to])) {
                $from = (string) $from;
                $to = (string) $to;
                $next[$from][] = $to;
                $incoming[$to] = true;
                $passes = NodeConfigValidator::passesApproval(NodeConfigValidator::edgeHandle($edge['condition'] ?? null));
                if (!$isApproval($from) || !$passes) {
                    $open[$from][] = $to;
                }
            }
        }
        $codes = array_keys($types);
        if ($next === [] && count($codes) > 1) {
            // No edges: steps run in order (the policy and taint lint convention).
            for ($i = 1; $i < count($codes); $i++) {
                $next[$codes[$i - 1]][] = $codes[$i];
                if (!$isApproval($codes[$i - 1])) {
                    $open[$codes[$i - 1]][] = $codes[$i];
                }
            }
            $incoming = array_fill_keys(array_slice($codes, 1), true);
        }

        $graphSteps = array_values(array_map(static fn (array $s): array => [
            'step_code' => (string) ($s['step_code'] ?? ''), 'step_type' => (string) ($s['step_type'] ?? ''),
            'step_config' => is_array($s['step_config'] ?? null) ? $s['step_config'] : [],
        ], $steps));

        $approvals = [];
        foreach ($types as $code => $type) {
            if (!$isApproval($code)) {
                continue;
            }
            $approvedNext = array_values(array_diff($next[$code] ?? [], $open[$code] ?? []));
            $after = self::reach($next, $approvedNext, []);
            if (array_intersect_key($writes, $after) === []) {
                continue;
            }
            $config = is_array($steps[$code]['step_config'] ?? null) ? $steps[$code]['step_config'] : [];
            $approvals[$code] = [
                'mode' => is_string($config['mode'] ?? null) && $config['mode'] !== '' ? $config['mode'] : 'human',
                'reversible_skip' => ($config['reversible_skip'] ?? true) !== false,
            ];
            $expires = $config['expires_after'] ?? null;
            if ((is_string($expires) && trim($expires) !== '') || is_int($expires) || is_float($expires)) {
                $approvals[$code]['expires_after'] = is_string($expires) ? trim($expires) : (string) $expires;
            }
            // Auto-publish after calibration: the level, the verdict read and the hold rule decide what goes out unseen.
            $jev = [
                'min_confidence' => is_numeric(data_get($config, 'jev.min_confidence')) ? (float) data_get($config, 'jev.min_confidence') : null,
                'verdict_from' => self::trimmed(data_get($config, 'jev.verdict_from')),
                'hold_if' => self::trimmed(data_get($config, 'jev.hold_if')),
            ];
            if (array_filter($jev, static fn (mixed $v): bool => $v !== null) !== []) {
                // Who produces the verdict, and who else could overwrite it before the approval reads it.
                $jev['producer'] = $jev['verdict_from'] === null ? [] : array_map(
                    static fn (string $c): string => $c . ':' . ($types[$c] ?? ''),
                    WorkflowAutoApproval::verdictProducers($jev['verdict_from'], $graphSteps),
                );
                $jev['writers'] = $jev['verdict_from'] === null ? [] : WorkflowAutoApproval::verdictWriters($jev['verdict_from'], $graphSteps);
                $approvals[$code]['jev'] = $jev;
            }
        }

        $starts = array_values(array_filter($codes, static fn (string $c): bool => (bool) ($steps[$c]['is_start'] ?? false)));
        if ($starts === []) {
            $starts = array_values(array_filter($codes, static fn (string $c): bool => !isset($incoming[$c])));
        }
        $reached = self::reach($open, $starts, []);
        $unguarded = array_keys(array_intersect_key($writes, $reached));
        sort($unguarded);

        return [$approvals, $unguarded];
    }

    /**
     * Steps reachable from $from (included), not walking past steps in $stopAt.
     *
     * @param array<string, list<string>> $next
     * @param list<string>                $from
     * @param array<string, true>         $stopAt
     * @return array<string, true>
     */
    private static function reach(array $next, array $from, array $stopAt): array
    {
        $seen = [];
        $queue = $from;
        while ($queue !== []) {
            $code = array_shift($queue);
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            if (!isset($stopAt[$code])) {
                array_push($queue, ...($next[$code] ?? []));
            }
        }

        return $seen;
    }

    /**
     * Credential names a config binds (`$bind:<name>` anywhere in it), sorted.
     *
     * @return list<string>
     */
    private static function bindNames(mixed $value): array
    {
        $names = [];
        if (is_string($value) && str_starts_with(trim($value), self::BIND_PREFIX)) {
            $names[] = trim(substr(trim($value), strlen(self::BIND_PREFIX)));
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $names = array_merge($names, self::bindNames($item));
            }
        }
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function writeTarget(array $config): ?string
    {
        foreach (['model', 'targetExpr', 'sourceExpr', 'subject_model'] as $key) {
            if (is_string($config[$key] ?? null) && trim($config[$key]) !== '') {
                return $key . ':' . trim($config[$key]);
            }
        }

        return null;
    }

    /**
     * Field names a write node sets (`changes` / `fields` rows), sorted; never their values.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function writeFields(array $config): array
    {
        $fields = [];
        foreach (['changes', 'fields'] as $key) {
            foreach ((array) ($config[$key] ?? []) as $row) {
                if (is_array($row) && is_string($row['field'] ?? null) && $row['field'] !== '') {
                    $fields[] = $row['field'];
                }
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    /**
     * What makes an automatic JEV approval looser: a lower `min_confidence` (unset = the 0.8 default), another verdict
     * read or another hold rule. Null when nothing of that changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function jevWeakened(array $before, array $after): ?string
    {
        $default = (float) config('core.workflow_nodes.human.jev.min_confidence', 0.8);
        $old = (float) ($before['min_confidence'] ?? $default);
        $new = (float) ($after['min_confidence'] ?? $default);
        if ($new < $old) {
            return "jev.min_confidence {$old} -> {$new}";
        }
        foreach (['verdict_from', 'hold_if', 'producer', 'writers'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                return "jev.{$key} changed";
            }
        }

        return null;
    }

    private static function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A setting at its harmless default (a manual start, no target) counts as not set. */
    private static function unlessDefault(mixed $value, string $default): mixed
    {
        return $value === null || $value === '' || $value === $default ? null : $value;
    }

    private static function raised(mixed $before, mixed $after): bool
    {
        if ($before === null) {
            return false; // no cap before: any value now is a cap, i.e. lower
        }
        if ($after === null) {
            return true; // a cap removed
        }

        return (float) $after > (float) $before;
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function stepDetail(array $step): string
    {
        return (string) ($step['type'] ?? '') . ' (' . implode(', ', (array) ($step['safety'] ?? [])) . ')';
    }

    /**
     * @param array<string, mixed> $write
     */
    private static function writeDetail(array $write): string
    {
        $fields = (array) ($write['fields'] ?? []);

        return trim((string) ($write['type'] ?? '') . ' ' . ($write['target'] ?? '') . ($fields !== [] ? ' [' . implode(', ', $fields) . ']' : ''));
    }

    /**
     * Who / where / how of a send step: its target keys, canonical, with surrounding blanks trimmed.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function sendTarget(array $config, string $type = ''): array
    {
        $target = [];
        foreach ($type === 'email_send' ? [...self::SEND_TARGET_KEYS, ...self::EMAIL_TARGET_KEYS] : self::SEND_TARGET_KEYS as $key) {
            $value = $config[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $target[$key] = self::canonical($value);
            }
        }

        return $target;
    }

    /**
     * Where a config places each credential: dot path => `$bind` name, sorted by path.
     *
     * @return array<string, string>
     */
    private static function bindPlaces(mixed $value, string $path = ''): array
    {
        $places = [];
        if (is_string($value) && str_starts_with(trim($value), self::BIND_PREFIX)) {
            $places[$path] = trim(substr(trim($value), strlen(self::BIND_PREFIX)));
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $places += self::bindPlaces($item, $path === '' ? (string) $key : $path . '.' . $key);
            }
        }
        ksort($places);

        return $places;
    }

    /**
     * Changes inside one existing send step: a changed recipient, URL, method or login is `host_added` (a changed URL
     * built by an expression reads `(expression)`), a credential moved elsewhere in the config `credential_changed`.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<array{kind: string, step_code: string, detail: string}>
     */
    private static function sendChanges(string $code, array $before, array $after): array
    {
        $changes = [];
        $type = (string) ($after['type'] ?? '');
        $old = (array) ($before['target'] ?? []);
        foreach ((array) ($after['target'] ?? []) as $key => $value) {
            if (($old[$key] ?? null) === $value || ($key === 'credential' && is_string($value) && str_starts_with($value, self::BIND_PREFIX))) {
                continue; // the credential itself is covered by `binds` below
            }
            $detail = "{$type} {$key}";
            if ($key === 'url') {
                $host = is_string($value) && !str_contains($value, '{{') ? parse_url($value, PHP_URL_HOST) : null;
                $detail = is_string($host) && $host !== '' ? strtolower($host) : '(expression)';
            }
            $changes[] = ['kind' => 'host_added', 'step_code' => $code, 'detail' => $detail];
        }

        $oldBinds = (array) ($before['binds'] ?? []);
        $moved = [];
        foreach ((array) ($after['binds'] ?? []) as $path => $name) {
            if (($oldBinds[$path] ?? null) !== $name) {
                $moved[] = (string) $name;
            }
        }
        if ($moved !== []) {
            $moved = array_values(array_unique($moved));
            sort($moved);
            $changes[] = ['kind' => 'credential_changed', 'step_code' => $code, 'detail' => implode(', ', $moved)];
        }

        return $changes;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
