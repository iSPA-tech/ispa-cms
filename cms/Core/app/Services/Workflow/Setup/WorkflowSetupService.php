<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Setup;

use App\Models\User;
use Cms\Core\Http\Controllers\Workflow\WorkflowReviewerController;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Services\Workflow\Package\WorkflowPackageBindings;
use Cms\Core\Services\Workflow\Presets\WorkflowPresetCatalog;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Services\Workflow\WorkflowScheduleService;
use Cms\Core\Policies\AbstractModelPolicy;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Human\HumanNotifier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Setup questions of an installed sample workflow: only what the site itself must decide
 * (`GET|PUT api/v2/admin/workflows/{project}/setup`).
 *
 * Questions come from the draft rows (the designer's steps; runs execute published versions):
 * - a node schema field marked `x-setup: <kind>` (`reviewers`, `notify_channel`, `credential`, `required_field`,
 *   `choice`: a yes/no the site picks, stored as the expression 'true' or 'false' of a decision with `ask_site`);
 *   a field with `x-secret` counts as `credential` and one with `x-required: true` as `required_field` when unmarked.
 *   `x-setup-when: {configKey: [values]}` limits the question to steps whose config (or default) has one of the values;
 * - `schedule` (project level) unless the start step is an event trigger.
 *
 * A question is `answered` when its value is non-empty and valid: a reviewer permission that exists (or named
 * assignees), a notify permission that exists (or named users), an existing credential of an accepted type, a filled
 * required field, a cron or the "run only by hand" choice. `current` never carries a credential value, only its name.
 *
 * apply() writes answers to the draft rows after the same checks as a designer save
 * (WorkflowPublishPolicy::draftProblems) and sets the schedule through WorkflowScheduleService (scheduler ownership).
 * When the start step is an unattended event trigger (model event, event, webhook, error), the person who answers the
 * setup becomes the user those runs act for (`scheduled_by_user_id`), as when they save the trigger in the designer:
 * a template installed from the console or by someone else would otherwise never start a run.
 *
 * describe() leaves out the questions of steps the site switched off with a `choice` (reachable only through the
 * `true` edge of a decision answered "no"): they are asked once the branch is switched on.
 *
 * @package Cms\Core\Services\Workflow\Setup
 * @since   2026-11-24
 */
class WorkflowSetupService
{
    public const KINDS = ['reviewers', 'notify_channel', 'schedule', 'credential', 'required_field', 'choice'];

    /** Start step types whose runs come from an event, so a schedule is not asked. */
    private const EVENT_TRIGGERS = ['trigger_webhook', 'trigger_model_event', 'trigger_event', 'trigger_error', 'trigger_sub_workflow'];

    public function __construct(
        protected WorkflowPublishPolicy $policy,
        protected WorkflowScheduleService $schedules,
        protected WorkflowPresetCatalog $presets,
    ) {
    }

    /**
     * The GET shape: `{complete, questions, preset}`.
     *
     * @return array{complete: bool, questions: list<array<string, mixed>>, preset: list<array{label: string}>}
     */
    public function describe(WorkflowProject $project): array
    {
        $graph = WorkflowVersionSnapshot::fromDraft($project);
        $off = $this->switchedOffSteps($graph);
        $questions = array_map(
            fn (array $q): array => $this->publicQuestion($q),
            array_values(array_filter($this->questions($project, $graph), static fn (array $q): bool => !isset($off[$q['step_code'] ?? '']))),
        );

        return [
            'complete' => array_filter($questions, static fn (array $q): bool => $q['required'] && !$q['answered']) === [],
            'questions' => $questions,
            'preset' => $this->preset($graph),
        ];
    }

    /**
     * Apply answers (`{<question key>: value}`) to the draft. Returns the problems that refused them (nothing is
     * written then), or [] when every answer was saved.
     *
     * @param array<string, mixed> $answers
     * @return list<array<string, string>>
     */
    public function apply(WorkflowProject $project, array $answers, Authenticatable $user): array
    {
        $graph = WorkflowVersionSnapshot::fromDraft($project);
        $byKey = [];
        foreach ($this->questions($project, $graph) as $question) {
            $byKey[$question['key']] = $question;
        }

        $configs = [];
        foreach ($graph['steps'] as $step) {
            $configs[(string) $step['step_code']] = is_array($step['step_config']) ? $step['step_config'] : [];
        }

        $problems = [];
        $schedule = null;
        foreach ($answers as $key => $value) {
            $question = $byKey[(string) $key] ?? null;
            if ($question === null) {
                $problems[] = ['key' => (string) $key, 'code' => 'unknown_question', 'message' => "{$key}: not a setup question of this workflow."];
                continue;
            }

            if ($question['kind'] === 'schedule') {
                $value = is_array($value) ? $value : ['cron' => $value];
                $cron = $value['cron'] ?? null;
                $timezone = $value['timezone'] ?? null;
                if ($problem = $this->schedules->problem($project, $cron, $timezone)) {
                    $problems[] = ['key' => 'schedule', 'code' => $problem['code'], 'message' => 'schedule: ' . $problem['message']];
                } else {
                    $schedule = ['cron' => is_string($cron) ? $cron : null, 'timezone' => is_string($timezone) ? $timezone : null];
                }
                continue;
            }

            $code = $question['step_code'];
            $error = $this->applyStepAnswer($question, $value, $configs[$code]);
            if ($error !== null) {
                $problems[] = ['key' => (string) $key, 'step_code' => $code, 'code' => 'answer_invalid', 'message' => "{$key}: {$error}"];
            }
        }
        if ($problems !== []) {
            return $problems;
        }
        if ($schedule !== null && ($start = self::scheduleStart($graph)) !== null) {
            // The `trigger_schedule` start node is the designer's editor of the project schedule (TriggerConfigSync):
            // it gets the answer too, so a later save of that node keeps the schedule instead of clearing it.
            $configs[$start]['cron'] = trim((string) $schedule['cron']);
            $configs[$start]['timezone'] = $schedule['timezone'];
        }

        // The same checks as a designer save, on the draft with the answers in.
        $draft = $graph;
        foreach ($draft['steps'] as $i => $step) {
            $draft['steps'][$i]['step_config'] = $configs[(string) $step['step_code']];
        }
        $problems = $this->policy->draftProblems($draft, $user, $project);
        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($project, $graph, $configs, $schedule, $user): void {
            foreach ($graph['steps'] as $step) {
                $code = (string) $step['step_code'];
                if ($configs[$code] !== $step['step_config']) {
                    WorkflowStep::query()->findOrFail($step['id'])->forceFill(['step_config' => $configs[$code]])->save();
                }
            }
            if ($schedule !== null) {
                $cron = trim((string) $schedule['cron']);
                $this->schedules->apply($project, $cron, $schedule['timezone'], $user, $cron === '');
            }
            if ($this->startsOnEvent($graph)) {
                // A query update: no model events, so the schedule-ownership observer is not re-entered.
                WorkflowProject::query()->whereKey($project->getKey())->update(['scheduled_by_user_id' => $user->getAuthIdentifier()]);
            }
        });

        return [];
    }

    /**
     * The step config fields that hold setup answers in a graph of the project: every step setup question's field
     * plus `assignees` of a reviewers question (the people named next to the permission). The one shared list of
     * "setup answers": the hub template hash leaves these out and an update in place carries them over.
     *
     * @param array<string, mixed> $graph canonical graph ({@see WorkflowVersionSnapshot})
     * @return array<string, list<string>> step code => fields
     */
    public function answerFields(WorkflowProject $project, array $graph): array
    {
        $fields = [];
        foreach ($this->questions($project, $graph) as $question) {
            if (!is_string($question['step_code'] ?? null) || !is_string($question['field'] ?? null)) {
                continue;
            }
            $fields[$question['step_code']][] = $question['field'];
            if ($question['kind'] === 'reviewers') {
                $fields[$question['step_code']][] = 'assignees';
            }
            if ($question['kind'] === 'notify_channel' && $question['field'] === 'permission') {
                $fields[$question['step_code']][] = 'users';
            }
        }

        // The schedule answer also sits on a `trigger_schedule` start node (see apply()).
        if (($start = self::scheduleStart($graph)) !== null) {
            $fields[$start] = [...($fields[$start] ?? []), 'cron', 'timezone'];
        }

        return array_map(static fn (array $list): array => array_values(array_unique($list)), $fields);
    }

    /** Step code of a `trigger_schedule` start step of the graph, or null. */
    private static function scheduleStart(array $graph): ?string
    {
        foreach ($graph['steps'] as $step) {
            if (($step['is_start'] ?? false) && ($step['step_type'] ?? null) === 'trigger_schedule') {
                return (string) $step['step_code'];
            }
        }

        return null;
    }

    /**
     * Every question with its internals (`schema`, `type`) for apply().
     *
     * @param array<string, mixed> $graph
     * @return list<array<string, mixed>>
     */
    protected function questions(WorkflowProject $project, array $graph): array
    {
        $registry = NodeRegistry::current();
        $steps = $graph['steps'];
        usort($steps, static fn (array $a, array $b): int => [$a['position_x'], $a['position_y'], (string) $a['step_code']] <=> [$b['position_x'], $b['position_y'], (string) $b['step_code']]);

        $questions = [];
        $startType = null;
        foreach ($steps as $step) {
            $type = (string) $step['step_type'];
            if ($step['is_start']) {
                $startType ??= $type;
            }
            $definition = $registry->get($type);
            if ($definition === null) {
                continue;
            }

            $config = is_array($step['step_config']) ? $step['step_config'] : [];
            foreach ((array) ($definition->configSchema['properties'] ?? []) as $field => $schema) {
                $kind = is_array($schema) ? self::kindOf($schema) : null;
                if ($kind === null || !is_string($field) || !$this->applies($schema, $config, $definition->defaultConfig)) {
                    continue;
                }

                $question = [
                    'key' => $step['step_code'] . '.' . $field,
                    'kind' => $kind,
                    'step_code' => (string) $step['step_code'],
                    'field' => $field,
                    'step_name' => (string) ($step['name'] ?: $step['step_code']),
                    'type' => $type,
                    'schema' => $schema,
                    'required' => true,
                ];
                if ($kind === 'notify_channel' && $field === 'permission') {
                    $question['suggested'] = $suggested ??= ($this->suggestedNoticePermission($graph) ?? false);
                    $question['suggested'] = $question['suggested'] ?: null;
                }
                [$question['answered'], $question['current']] = $this->state($question, $config);
                $questions[] = $question;
            }
        }

        if (!in_array($startType, self::EVENT_TRIGGERS, true)) {
            $byHand = in_array(WorkflowScheduleService::MANUAL_TAG, (array) ($project->tags ?? []), true);
            $cron = trim((string) $project->schedule_cron);
            $questions[] = [
                'key' => 'schedule',
                'kind' => 'schedule',
                'required' => true,
                'answered' => $cron !== '' || $byHand,
                'current' => ['cron' => $cron !== '' ? $cron : null, 'timezone' => $project->schedule_timezone, 'by_hand' => $cron === '' && $byHand],
            ];
        }

        return $questions;
    }

    /** The setup kind of a schema field, or null when it is no question. */
    public static function kindOf(array $schema): ?string
    {
        $kind = $schema['x-setup'] ?? null;
        if (is_string($kind) && in_array($kind, self::KINDS, true) && $kind !== 'schedule') {
            return $kind;
        }
        if (is_string($schema['x-secret'] ?? null)) {
            return 'credential';
        }

        return ($schema['x-required'] ?? false) === true ? 'required_field' : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     * @param array<string, mixed> $defaults
     */
    protected function applies(array $schema, array $config, array $defaults): bool
    {
        foreach ((array) ($schema['x-setup-when'] ?? []) as $key => $values) {
            $value = $config[$key] ?? $defaults[$key] ?? null;
            if (!is_scalar($value) || !in_array((string) $value, array_map('strval', (array) $values), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * [answered, current] of a step question.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $config
     * @return array{0: bool, 1: mixed}
     */
    protected function state(array $question, array $config): array
    {
        $value = $config[$question['field']] ?? null;

        switch ($question['kind']) {
            case 'reviewers':
                $permission = is_string($value) && trim($value) !== '' ? trim($value) : null;
                $assignees = $question['type'] === 'approval' ? array_values(array_filter((array) ($config['assignees'] ?? []), 'is_string')) : [];

                return [($permission !== null && self::permissionExists($permission)) || $assignees !== [],
                    ['permission' => $permission, 'assignees' => $assignees]];
            case 'notify_channel':
                if ($question['field'] === 'permission') {
                    $permission = is_string($value) && trim($value) !== '' ? trim($value) : null;
                    $users = array_values(array_filter((array) ($config['users'] ?? []), 'is_string'));

                    return [($permission !== null && self::permissionExists($permission)) || $users !== [],
                        ['permission' => $permission, 'users' => $users, 'suggested' => $question['suggested'] ?? null]];
                }

                return [!self::isEmpty($value), $value];
            case 'credential':
                $name = WorkflowCredentialStore::bindingName($value);

                return [$name !== null && $this->credentialProblem($name, $question['schema']) === null, $name];
            case 'choice':
                $on = self::choiceValue($value);

                return [$on !== null, $on];
            default:
                return [!self::isEmpty($value), $value];
        }
    }

    /**
     * Put one answer into the step config. Returns why it was refused, or null.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $config
     */
    protected function applyStepAnswer(array $question, mixed $value, array &$config): ?string
    {
        $field = $question['field'];

        switch ($question['kind']) {
            case 'reviewers':
                $answer = is_array($value) ? $value : ['permission' => $value];
                if (array_key_exists('permission', $answer)) {
                    $permission = is_string($answer['permission']) ? trim($answer['permission']) : '';
                    if ($permission !== '' && !self::permissionExists($permission)) {
                        return "permission {$permission} does not exist.";
                    }
                    $config[$field] = $permission !== '' ? $permission : null;
                }
                if (array_key_exists('assignees', $answer)) {
                    if ($question['type'] !== 'approval') {
                        // Only an approval step names reviewers; an empty list from the form is no answer at all.
                        if (!self::isEmpty($answer['assignees'])) {
                            return 'this step has no named reviewers.';
                        }

                        return null;
                    }
                    $config['assignees'] = is_array($answer['assignees']) ? array_values($answer['assignees']) : $answer['assignees'];
                }

                return null;
            case 'notify_channel':
                if ($field === 'permission') {
                    // `{permission, users}` (people first, or everyone who may see a kind of record); a bare string is
                    // a permission (answers saved before 2026-11-27).
                    $answer = is_array($value) ? $value : ['permission' => $value];
                    $permission = is_string($answer['permission'] ?? null) ? trim($answer['permission']) : '';
                    $users = array_values(array_unique(array_filter((array) ($answer['users'] ?? []), static fn ($uuid): bool => is_string($uuid) && $uuid !== '')));
                    if ($permission !== '' && !self::permissionExists($permission)) {
                        return 'choose a permission that exists.';
                    }
                    if ($users !== [] && User::query()->whereIn('uuid', $users)->count() !== count($users)) {
                        return 'choose people who have an account here.';
                    }
                    if ($permission === '' && $users === []) {
                        return 'choose who is told: people or a permission.';
                    }
                    $config[$field] = $permission !== '' ? $permission : null;
                    if ($users !== []) {
                        $config['users'] = $users;
                    } else {
                        unset($config['users']);
                    }

                    return null;
                }
                if (self::isEmpty($value)) {
                    return 'an answer is required.';
                }
                $config[$field] = $value;

                return null;
            case 'credential':
                $name = WorkflowCredentialStore::bindingName($value) ?? (is_string($value) ? trim($value) : '');
                if ($name === '' || !preg_match(WorkflowCredentialStore::NAME_PATTERN, $name)) {
                    return 'choose a saved credential.';
                }
                if ($problem = $this->credentialProblem($name, $question['schema'])) {
                    return $problem;
                }
                $config[$field] = WorkflowCredentialStore::BIND_PREFIX . $name;

                return null;
            case 'choice':
                $on = self::choiceValue($value);
                if ($on === null) {
                    return 'answer yes or no.';
                }
                $config[$field] = $on ? 'true' : 'false';

                return null;
            default:
                if (self::isEmpty($value)) {
                    return 'an answer is required.';
                }
                $config[$field] = $value;

                return null;
        }
    }

    /** @param array<string, mixed> $schema */
    protected function credentialProblem(string $name, array $schema): ?string
    {
        $type = WorkflowCredential::query()->where('name', $name)->value('type');
        if ($type === null) {
            return "credential {$name} does not exist.";
        }
        $kind = $schema['x-secret'] ?? null;
        $accepted = is_string($kind) ? (WorkflowPackageBindings::CREDENTIAL_TYPES[$kind] ?? [$kind]) : null;

        return $accepted !== null && !in_array($type, $accepted, true) ? "credential {$name} is not of the kind this step needs." : null;
    }

    /**
     * What the sample already sets, in plain words.
     *
     * @param array<string, mixed> $graph
     * @return list<array{label: string}>
     */
    protected function preset(array $graph): array
    {
        $lines = [];
        $steps = $graph['steps'];
        usort($steps, static fn (array $a, array $b): int => [$a['position_x'], $a['position_y']] <=> [$b['position_x'], $b['position_y']]);
        $policyGraph = ['steps' => $steps, 'connections' => $graph['connections']];

        foreach ($steps as $step) {
            $config = is_array($step['step_config']) ? $step['step_config'] : [];

            if (is_string($config['criteria_preset'] ?? null) && ($set = $this->presets->find($config['criteria_preset'])) !== null) {
                $lines[] = self::text('text.workflow.setup.preset.jev_criteria', 'JEV rereads it against the “:name” criteria', ['name' => $set['label']]);
            }

            $editable = array_values(array_filter((array) ($config['editable_fields'] ?? []), 'is_string'));
            if ($step['step_type'] === 'approval' && $editable !== []) {
                $names = array_map(static fn (string $f): string => self::text('text.workflow.result.' . $f, Str::headline($f)), $editable);
                $lines[] = self::text('text.workflow.setup.preset.editable_fields', 'Reviewers may edit: :fields', ['fields' => implode(', ', $names)]);
            }
        }

        if ($this->policy->publishesOnlyAfterApproval($policyGraph)) {
            $lines[] = self::text('text.workflow.setup.preset.publish_after_review', 'Nothing is published until a person approves it');
        }

        return array_map(static fn (string $label): array => ['label' => $label], array_values(array_unique($lines)));
    }

    /**
     * The contract shape of a question (label/help in the user's language; no internals).
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    protected function publicQuestion(array $q): array
    {
        $kind = $q['kind'];
        $replace = ['step' => $q['step_name'] ?? '', 'field' => isset($q['field'])
            ? self::text('text.workflow.setup.field.' . $q['field'], Str::headline($q['field'])) : ''];
        [$label, $help] = match ($kind) {
            'reviewers' => ['Who reviews at “:step”?', 'Everyone with the chosen permission may decide; people you name are told first.'],
            'notify_channel' => ['Who is told at “:step”?', 'The people or chat that receive the message of this step.'],
            'credential' => ['Which connected account does “:step” use?', 'Pick an account saved in the workflow connections; its secret is never shown here.'],
            'choice' => [':step', 'Turn it on or off for this site; you can change it later.'],
            'required_field' => ['“:step” needs: :field', 'The step cannot run while this is empty.'],
            default => ['When does it run?', 'Pick a time, or run it only by hand.'],
        };

        $out = ['key' => $q['key'], 'kind' => $kind];
        if (isset($q['step_code'])) {
            $out['step_code'] = $q['step_code'];
            $out['field'] = $q['field'];
        }

        return $out + [
            'label' => self::text("text.workflow.setup.q.{$kind}", $label, $replace),
            'help' => self::text("text.workflow.setup.q.{$kind}_help", $help, $replace),
            'required' => $q['required'],
            'answered' => $q['answered'],
            'current' => $q['current'],
        ] + ($kind === 'reviewers' ? ['assignees_allowed' => ($q['type'] ?? null) === 'approval'] : []);
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, string $fallback, array $replace = []): string
    {
        $text = __($key, $replace);
        if (is_string($text) && $text !== $key) {
            return $text;
        }

        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = $value;
        }

        return strtr($fallback, $pairs);
    }

    /** A yes/no answer (or stored decision expression) as a bool, or null when it is neither. */
    public static function choiceValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * Whether the start step is an unattended event trigger whose runs act for `scheduled_by_user_id`.
     *
     * @param array<string, mixed> $graph
     */
    protected function startsOnEvent(array $graph): bool
    {
        foreach ($graph['steps'] as $step) {
            if ($step['is_start'] && in_array((string) $step['step_type'], ['trigger_model_event', 'trigger_event', 'trigger_webhook', 'trigger_error'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Step codes reachable only through the `true` edge of a `choice` decision answered "no" (a branch the site
     * switched off). Walks the graph from the start steps without taking those edges. Also read by WorkflowTakeover on
     * a published graph (same snapshot shape: step codes in `connections`).
     *
     * @param array<string, mixed> $graph
     * @return array<string, true>
     */
    public static function switchedOffSteps(array $graph): array
    {
        $codes = [];
        $blocked = [];
        $starts = [];
        foreach ($graph['steps'] as $step) {
            $code = (string) $step['step_code'];
            $codes[$code] = true;
            if ($step['is_start']) {
                $starts[] = $code;
            }
            $config = is_array($step['step_config']) ? $step['step_config'] : [];
            if ($step['step_type'] === 'decision' && self::choiceValue($config['ask_site'] ?? null) === true
                && self::choiceValue($config['expression'] ?? null) === false) {
                $blocked[$code] = true;
            }
        }
        if ($blocked === [] || $starts === []) {
            return [];
        }

        $next = [];
        foreach ((array) ($graph['connections'] ?? []) as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $condition = is_array($edge['condition'] ?? null) ? $edge['condition'] : [];
            $handle = $condition['source_handle'] ?? $condition['condition_value'] ?? null;
            if (isset($blocked[$from]) && in_array($handle, ['true', 'yes'], true)) {
                continue;
            }
            $next[$from][] = (string) ($edge['to'] ?? '');
        }

        $seen = [];
        $queue = $starts;
        while ($queue !== []) {
            $code = array_shift($queue);
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            array_push($queue, ...($next[$code] ?? []));
        }

        return array_diff_key($codes, $seen);
    }

    /**
     * Who a notice step would reach with these answers (the notify runner's own rule), for the setup preview.
     *
     * @param list<string> $userUuids
     * @return array{count: int, people: list<array{uuid: string, name: string, detail: string|null}>, more: int}
     */
    public function noticeRecipients(?string $permission, array $userUuids): array
    {
        $permission = is_string($permission) && trim($permission) !== '' ? trim($permission) : null;
        $max = max(1, (int) config('core.workflow_nodes.human.notify.max_recipients', 50));
        $users = app(HumanNotifier::class)->recipients($permission, $userUuids, $max);
        $people = array_map(
            static fn ($user): array => [
                'uuid' => (string) $user->uuid,
                'name' => (string) ($user->name ?: $user->username ?: $user->email),
                'detail' => WorkflowReviewerController::detail($user),
            ],
            array_values($users)
        );

        return ['count' => count($people), 'people' => array_slice($people, 0, 8), 'more' => max(0, count($people) - 8)];
    }

    /**
     * The permission to suggest for "who is told": `viewAny` of the first record the workflow starts from or reads (an
     * app event's subjectModel(), a model event's or a retrieve step's model), when its policy and the permission row
     * exist. So a contact notice suggests the people who may read contact messages and a sign-up notice the people
     * who may see accounts. Null when nothing fits.
     *
     * @param array<string, mixed> $graph canonical graph ({@see WorkflowVersionSnapshot})
     */
    protected function suggestedNoticePermission(array $graph): ?string
    {
        $steps = $graph['steps'];
        usort($steps, static fn (array $a, array $b): int => [!$a['is_start'], $a['position_y'], $a['position_x']] <=> [!$b['is_start'], $b['position_y'], $b['position_x']]);

        foreach ($steps as $step) {
            $config = is_array($step['step_config']) ? $step['step_config'] : [];
            $model = match ((string) $step['step_type']) {
                'trigger_event' => is_string($config['event'] ?? null) && method_exists($config['event'], 'subjectModel') ? $config['event']::subjectModel() : null,
                'trigger_model_event', 'retrieve' => $config['model'] ?? null,
                default => null,
            };
            if (!is_string($model) || !class_exists($model)) {
                continue;
            }
            $policy = Gate::getPolicyFor($model);
            if ($policy instanceof AbstractModelPolicy && self::permissionExists($key = $policy->permissionFor('viewAny'))) {
                return $key;
            }
        }

        return null;
    }

    public static function permissionExists(string $key): bool
    {
        $dot = strrpos($key, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }

        return Permission::query()->where('group', substr($key, 0, $dot))->where('name', substr($key, $dot + 1))->exists();
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }
}
