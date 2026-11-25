<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Cms\Core\Services\Ai\Jev\JevJudgeResolver;
use Cms\Core\Services\Ai\Jev\JevRequest;
use Cms\Core\Services\Ai\Jev\JevVerdict;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiUsageMeter;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Throwable;

/**
 * `jev_review` node: a JEV judge reads `content` (expression, untrusted) against `criteria` (trusted text) and the node
 * leaves through `pass`, `fail` or `unsure`. A verdict below `min_confidence`, or no verdict at all (judge off,
 * unavailable, budget refused), leaves through `unsure`, never `pass`. `mode: shadow` records the verdict and always
 * leaves through `unsure`, for calibrating a judge before it decides anything. Writes nothing; reports AI usage.
 *
 * Result in `vars.<storeAs>` (default `vars.jev`): verdict, confidence, reasons, status, mode, model.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class JevReviewRunner implements StepRunner
{
    use NodeHelpers;

    public const OUTPUTS = ['pass', 'fail', 'unsure'];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $path = $this->storePath($config, 'vars.jev');
        if ($path === null) {
            return StepRunResult::failed('jev_review: storeAs must start with vars.');
        }
        $criteria = is_string($config['criteria'] ?? null) ? trim($config['criteria']) : '';
        if ($criteria === '') {
            return StepRunResult::failed('jev_review: criteria are required');
        }
        $mode = ($config['mode'] ?? 'active') === 'shadow' ? 'shadow' : 'active';

        try {
            $content = $this->evalField($config, 'content', $context);
        } catch (Throwable $e) {
            return StepRunResult::failed('jev_review: ' . $e->getMessage());
        }
        if ($content === null || $content === '' || $content === []) {
            return StepRunResult::failed('jev_review: content is empty');
        }

        try {
            $verdict = JevJudgeResolver::judge()->judge(new JevRequest($criteria, $content, [], 'workflow_jev_review', $mode));
        } catch (Throwable $e) {
            report($e);
            $verdict = JevVerdict::unavailable('unavailable', 'judge_error', $mode);
        }

        $min = is_numeric($config['min_confidence'] ?? null)
            ? max(0.5, min(1.0, (float) $config['min_confidence']))
            : (float) config('core.workflow_nodes.human.jev.min_confidence', 0.8);
        $output = $verdict->decides($min) ? $verdict->verdict : JevVerdict::UNSURE;

        $context->setVarPath($path, $verdict->toArray() + ['output' => $output, 'min_confidence' => $min]);

        // `confidence` lets an approval check that the verdict it reads is this one (WorkflowAutoApproval::matchesProducerResult()).
        $result = StepRunResult::success(['verdict' => $verdict->verdict, 'output' => $output, 'status' => $verdict->status, 'confidence' => $verdict->confidence]);
        $result = $this->routeOutput($result, $step, $output, self::OUTPUTS, $output !== JevVerdict::PASS);

        return $result->withUsage($verdict->usage ?? AiUsageMeter::none('workflow_jev_review'));
    }
}
