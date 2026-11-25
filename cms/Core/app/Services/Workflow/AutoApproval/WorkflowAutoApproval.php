<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\AutoApproval;

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Auto-publish after JEV calibration.
 *
 * An approval step with `jev.verdict_from` reads a verdict an earlier step stored (no second judge call). In mode
 * `human` it is "learning": a person decides everything and the verdict is recorded on the row. In mode `jev` an item
 * is approved automatically only when JEV assessed it, its probability reaches `jev.min_confidence` and `jev.hold_if`
 * is false; anything else waits for a person (never an automatic rejection). The mode can be switched to `jev` only
 * while the calibration is ready (WorkflowPublishPolicy: `auto_approval_not_ready`), and the switch is sensitive
 * (WorkflowSensitiveFingerprint). Before an automatic decision the rolling agreement of the latest decisions is
 * checked: below the minimum the item goes to a person (`held_reason: paused`) and the publishers are told once
 * (WorkflowChangeAlerter `auto_approval_paused`) until the agreement recovers.
 *
 * Calibration comes from the provider registered for the step's artifact type
 * (`core.workflow_nodes.human.calibration_providers`), else Core's {@see StepApprovalCalibration}.
 *
 * @package Cms\Core\Services\Workflow\AutoApproval
 * @since   2026-11-24
 */
class WorkflowAutoApproval
{
    public const HELD_REASONS = ['learning', 'below_threshold', 'flagged', 'jev_unsure', 'jev_missing', 'paused'];

    private const PAUSE_KEY = 'workflow:auto-approval-paused:';

    public static function requiredDecisions(): int
    {
        return max(1, (int) config('core.workflow_nodes.human.jev.calibration_min_decisions', 50));
    }

    public static function minimumAgreement(): float
    {
        return (float) config('core.workflow_nodes.human.jev.calibration_min_agreement', 0.9);
    }

    /** Latest decisions the auto-pause and the estimate look at. */
    public static function window(): int
    {
        return max(1, (int) config('core.workflow_nodes.human.jev.calibration_window', 50));
    }

    /**
     * The levels the designer offers (Dễ / Vừa / Chặt).
     *
     * @return list<float>
     */
    public static function levels(): array
    {
        $levels = array_values(array_filter((array) config('core.workflow_nodes.human.jev.auto_levels', [0.6, 0.8, 0.9]), 'is_numeric'));

        return array_map('floatval', $levels);
    }

    /** `jev.verdict_from` of an approval step config, or null when the step does not read a stored verdict. */
    public static function verdictFrom(array $config): ?string
    {
        $value = data_get($config, 'jev.verdict_from');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Whether an approval step config turns auto-publish on (`mode: jev` with `jev.verdict_from`). */
    public static function isOn(array $config): bool
    {
        return ($config['mode'] ?? null) === 'jev' && self::verdictFrom($config) !== null;
    }

    /** `jev.min_confidence` as the runner applies it (default from config, never below 0.5). */
    public static function minConfidence(array $config): float
    {
        $value = data_get($config, 'jev.min_confidence');
        $value = is_numeric($value) ? (float) $value : (float) config('core.workflow_nodes.human.jev.min_confidence', 0.8);

        return max(0.5, min(1.0, $value));
    }

    /**
     * Whether a node type is a JEV judge whose stored result may serve as an auto-publish verdict: Core's `jev_review`
     * or a module's `<module>.jev_review` (module nodes come only from allowlisted, reviewed namespaces).
     */
    public static function isVerdictNode(string $type): bool
    {
        return $type === 'jev_review' || str_ends_with($type, '.jev_review');
    }

    /**
     * Step codes of the JEV judge steps a `jev.verdict_from` reads (review 2026-11-24 finding 6): judge steps whose
     * `storeAs` is exactly that path, or `steps.<code>` of a judge step. Anything else (a variable a `set_variable`
     * fills, a literal map) has no producer.
     *
     * @param list<array<string, mixed>> $steps Graph steps (step_code, step_type, step_config).
     * @return list<string>
     */
    public static function verdictProducers(string $verdictFrom, array $steps): array
    {
        $verdictFrom = trim($verdictFrom);
        $codes = [];
        foreach ($steps as $step) {
            $type = (string) data_get($step, 'step_type', '');
            $code = (string) data_get($step, 'step_code', '');
            if ($code === '' || !self::isVerdictNode($type)) {
                continue;
            }
            $storeAs = data_get($step, 'step_config.storeAs');
            if ((is_string($storeAs) && trim($storeAs) !== '' && trim($storeAs) === $verdictFrom) || $verdictFrom === 'steps.' . $code) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * Step codes other than the producers that write the verdict path (the path itself, a part of it or a parent),
     * so they could replace the judge's verdict before the approval reads it.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<string>
     */
    public static function verdictWriters(string $verdictFrom, array $steps): array
    {
        $verdictFrom = trim($verdictFrom);
        if (!str_starts_with($verdictFrom, 'vars.')) {
            return [];
        }
        $producers = self::verdictProducers($verdictFrom, $steps);
        $codes = [];
        foreach ($steps as $step) {
            $code = (string) data_get($step, 'step_code', '');
            $config = data_get($step, 'step_config');
            if ($code === '' || in_array($code, $producers, true) || !is_array($config)) {
                continue;
            }
            foreach (\Cms\Core\Workflows\Nodes\NodeConfigValidator::writePaths($config) as $path) {
                if ($path === $verdictFrom || str_starts_with($path, $verdictFrom . '.') || str_starts_with($verdictFrom, $path . '.')) {
                    $codes[] = $code;
                    break;
                }
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * Whether a stored verdict is the one its producer recorded in this run: the producer's step result carries the
     * same `verdict` and the same `status` / `probability` / `confidence` wherever it reports them. A later step that
     * rewrote the variable fails this.
     *
     * @param array<string, mixed> $result The producer's step result.
     */
    public static function matchesProducerResult(mixed $stored, mixed $result): bool
    {
        if (!is_array($stored) || !is_array($result) || !is_string($result['verdict'] ?? null) || ($stored['verdict'] ?? null) !== $result['verdict']) {
            return false;
        }
        foreach (['status', 'probability', 'confidence'] as $key) {
            if (!array_key_exists($key, $result)) {
                continue;
            }
            $mine = $stored[$key] ?? null;
            $same = is_numeric($mine) && is_numeric($result[$key]) ? (float) $mine === (float) $result[$key] : $mine === $result[$key];
            if (!$same) {
                return false;
            }
        }

        return true;
    }

    /**
     * A stored verdict normalised: `verdict`, `status` and `probability` (the `probability` field, or the confidence of
     * a `pass`; null otherwise). Null when the value is not a verdict.
     *
     * @return array{verdict: string|null, status: string|null, probability: float|null}|null
     */
    public static function normalizeVerdict(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $verdict = is_string($raw['verdict'] ?? null) && $raw['verdict'] !== '' ? $raw['verdict'] : null;
        if ($verdict === null) {
            return null;
        }
        $probability = null;
        if (is_numeric($raw['probability'] ?? null)) {
            $probability = (float) $raw['probability'];
        } elseif ($verdict === 'pass' && is_numeric($raw['confidence'] ?? null)) {
            $probability = (float) $raw['confidence'];
        }

        return [
            'verdict' => $verdict,
            'status' => is_string($raw['status'] ?? null) ? $raw['status'] : null,
            'probability' => $probability === null ? null : round($probability, 4),
        ];
    }

    /**
     * Why a person decides instead of JEV (null = JEV may approve), paused aside.
     *
     * @param array{verdict: string|null, status: string|null, probability: float|null}|null $verdict
     */
    public static function heldReason(?array $verdict, bool $hold, float $minConfidence): ?string
    {
        return match (true) {
            $verdict === null => 'jev_missing',
            $hold => 'flagged',
            $verdict['status'] !== 'assessed' || $verdict['probability'] === null => 'jev_unsure',
            $verdict['verdict'] === 'fail', $verdict['probability'] < $minConfidence => 'below_threshold',
            default => null,
        };
    }

    /**
     * The JEV block of an approval row as the inbox shows it: {verdict, probability, min_confidence} or null.
     *
     * @return array{verdict: string|null, probability: float|null, min_confidence: float|null}|null
     */
    public static function jevOf(AgentApproval $approval): ?array
    {
        $jev = data_get($approval->diff_data, 'workflow_node.jev');
        if (!is_array($jev)) {
            return null;
        }
        $normal = self::normalizeVerdict($jev);

        return [
            'verdict' => $normal['verdict'] ?? null,
            'probability' => $normal['probability'] ?? null,
            'min_confidence' => is_numeric($jev['min_confidence'] ?? null) ? (float) $jev['min_confidence'] : null,
        ];
    }

    /**
     * Workflow approval rows of one step of one project.
     *
     * @return Builder<AgentApproval>
     */
    public static function stepApprovals(int $projectId, string $stepCode): Builder
    {
        return AgentApproval::query()
            ->where('diff_data->workflow_node->step_code', $stepCode)
            ->whereHas('run', static fn (Builder $run) => $run
                ->where('trigger_mode', AgentApprovalService::WORKFLOW_NODE_TRIGGER)
                ->whereHas('workflowInstance', static fn (Builder $instance) => $instance->where('project_id', $projectId)));
    }

    /**
     * The calibration provider of a step: the one registered for the artifact type of its items (from $artifactType,
     * the step's latest approval row, or the node that builds its `review` envelope), else Core's.
     *
     * @param array<string, mixed>       $config
     * @param list<array<string, mixed>> $steps  The graph's steps (step_code, step_type, step_config).
     */
    public function providerFor(?WorkflowProject $project, string $stepCode, array $config, array $steps, ?string $artifactType = null): ApprovalCalibrationProvider
    {
        $registered = (array) config('core.workflow_nodes.human.calibration_providers', []);

        $artifactType ??= $project === null ? null : self::stepApprovals((int) $project->getKey(), $stepCode)
            ->orderByDesc('id')->first()?->artifact()->value('artifact_type');

        if ($artifactType === null && is_string($config['review'] ?? null)
            && preg_match('/^\s*vars\.([A-Za-z0-9_.]+)\.review\s*$/', $config['review'], $match) === 1) {
            $storeAs = 'vars.' . $match[1];
            foreach ($steps as $step) {
                if ((data_get($step, 'step_config.storeAs')) !== $storeAs) {
                    continue;
                }
                foreach ($registered as $type => $entry) {
                    if (in_array($step['step_type'] ?? null, (array) ($entry['source_nodes'] ?? []), true)) {
                        $artifactType = (string) $type;
                        break 2;
                    }
                }
            }
        }

        $class = $artifactType !== null ? ($registered[$artifactType]['provider'] ?? null) : null;
        if (is_string($class) && class_exists($class) && is_subclass_of($class, ApprovalCalibrationProvider::class)) {
            return app($class);
        }

        return app(StepApprovalCalibration::class);
    }

    /**
     * Calibration of a step ready to be compared with the policy rule. Without a project (a new workflow) the step's
     * own rows are none, so only a module provider can be ready.
     *
     * @param array<string, mixed>       $config
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    public function calibrationFor(?WorkflowProject $project, string $stepCode, array $config, array $steps): array
    {
        $provider = $this->providerFor($project, $stepCode, $config, $steps);
        if ($project === null && $provider instanceof StepApprovalCalibration) {
            return ['decided' => 0, 'required' => self::requiredDecisions(), 'agreement_rate' => null, 'minimum_agreement' => self::minimumAgreement(), 'ready' => false];
        }

        return $provider->calibration($project ?? new WorkflowProject(), $stepCode);
    }

    /**
     * `GET api/v2/admin/workflows/{project}/steps/{step_code}/auto-approval`; null when the project has no such step.
     *
     * @return array<string, mixed>|null
     */
    public function describe(WorkflowProject $project, string $stepCode): ?array
    {
        $steps = WorkflowStep::query()->where('project_id', $project->getKey())->get(['step_code', 'step_type', 'step_config']);
        $step = $steps->firstWhere('step_code', $stepCode);
        if ($step === null) {
            return null;
        }

        $config = is_array($step->step_config) ? $step->step_config : [];
        $graph = $steps->map(static fn (WorkflowStep $s): array => ['step_code' => (string) $s->step_code, 'step_type' => (string) $s->step_type, 'step_config' => is_array($s->step_config) ? $s->step_config : []])->all();
        $available = $step->step_type === 'approval' && self::verdictFrom($config) !== null;
        $mode = is_string($config['mode'] ?? null) && $config['mode'] !== '' ? $config['mode'] : 'human';

        $provider = $this->providerFor($project, $stepCode, $config, $graph);
        $calibration = $provider->calibration($project, $stepCode);
        $recent = $provider->recentDecisions($project, $stepCode, self::window());

        $estimate = [];
        foreach (self::levels() as $level) {
            $estimate[self::levelKey($level)] = count(array_filter($recent, static fn (array $row): bool => self::wouldApprove($row, $level)));
        }
        $estimate['of'] = count($recent);

        $since = now()->subDays(30);
        $rows = self::stepApprovals((int) $project->getKey(), $stepCode)->where('updated_at', '>=', $since)
            ->whereIn('status', ['approved', 'rejected'])->get(['id', 'status', 'diff_data']);

        return [
            'available' => $available,
            'mode' => $mode,
            'min_confidence' => self::minConfidence($config),
            'source' => $provider->source(),
            'decided' => (int) $calibration['decided'],
            'required' => (int) $calibration['required'],
            'agreed' => (int) $calibration['agreed'],
            'disagreed' => (int) $calibration['disagreed'],
            'agreement_rate' => $calibration['agreement_rate'],
            'minimum_agreement' => (float) $calibration['minimum_agreement'],
            'approved_after_edit' => (int) $calibration['approved_after_edit'],
            'excluded' => (int) $calibration['excluded'],
            'ready' => (bool) $calibration['ready'],
            'paused' => $available && $mode === 'jev' && self::belowMinimum($recent, (float) $calibration['minimum_agreement']),
            'estimate' => $estimate,
            'last_30_days' => [
                'auto' => $rows->filter(static fn (AgentApproval $a): bool => data_get($a->diff_data, 'workflow_node.decided_by') === 'jev')->count(),
                'human' => $rows->filter(static fn (AgentApproval $a): bool => data_get($a->diff_data, 'workflow_node.decided_by') === 'human')->count(),
                'withdrawn' => $provider->withdrawnSince($project, $stepCode, $since),
            ],
        ];
    }

    /**
     * Auto-pause check before an automatic decision: true when the latest decisions agree below the minimum. The
     * publishers are alerted once per pause; the mark is cleared when the agreement recovers.
     */
    public function paused(int $projectId, string $stepCode, ApprovalCalibrationProvider $provider): bool
    {
        $project = WorkflowProject::query()->find($projectId);
        if ($project === null) {
            return false;
        }

        $recent = $provider->recentDecisions($project, $stepCode, self::window());
        $minimum = (float) ($provider->calibration($project, $stepCode)['minimum_agreement'] ?? self::minimumAgreement());
        $key = self::PAUSE_KEY . $projectId . ':' . $stepCode;

        if (!self::belowMinimum($recent, $minimum)) {
            Cache::forget($key);

            return false;
        }

        if (Cache::add($key, now()->toIso8601String())) {
            try {
                app(WorkflowChangeAlerter::class)->notify('auto_approval_paused', $project, [
                    'step_code' => $stepCode,
                    'agreement_rate' => self::rate($recent),
                    'count' => count($recent),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return true;
    }

    /**
     * @param list<array{probability: float|null, verdict: string|null, agreement: bool|null}> $recent
     */
    public static function belowMinimum(array $recent, float $minimum): bool
    {
        $rate = self::rate($recent);

        return $rate !== null && $rate < $minimum;
    }

    /**
     * @param list<array{probability: float|null, verdict: string|null, agreement: bool|null}> $recent
     */
    public static function rate(array $recent): ?float
    {
        $agreed = count(array_filter($recent, static fn (array $row): bool => ($row['agreement'] ?? null) === true));
        $disagreed = count(array_filter($recent, static fn (array $row): bool => ($row['agreement'] ?? null) === false));

        return $agreed + $disagreed > 0 ? round($agreed / ($agreed + $disagreed), 4) : null;
    }

    /**
     * @param array{probability: float|null, verdict: string|null, agreement: bool|null} $row
     */
    private static function wouldApprove(array $row, float $level): bool
    {
        return ($row['verdict'] ?? null) !== 'fail' && is_numeric($row['probability'] ?? null) && (float) $row['probability'] >= $level;
    }

    private static function levelKey(float $level): string
    {
        return rtrim(rtrim(number_format($level, 2, '.', ''), '0'), '.');
    }
}
