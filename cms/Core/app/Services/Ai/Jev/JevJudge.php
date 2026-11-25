<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Jev;

/**
 * A JEV judge: reads content against criteria and returns a verdict (pass / fail / unsure) with reasons and a
 * confidence, never a side effect.
 *
 * Common contract of the JEV services that modules build on this judge (Helpdesk JevVerdict, Core
 * JevCrawlerShadowService and the content-review services of other modules):
 * - a mode: `off` (no call), `shadow` (assess and record, never decide), `active` (the verdict may decide);
 * - a status: `assessed`, or why there is no assessment (`disabled`, `skipped`, `unavailable`, `budget_refused`);
 * - a probability / confidence in 0..1 mapped to a recommendation (high = supported, low = review, middle = uncertain);
 * - reasons (short codes or sentences) and the model that answered; content is always untrusted data.
 *
 * Core binds AiTaskJevJudge (AiTaskRuntime task `workflow_jev_review`). A module replaces it by binding this interface
 * in its provider; JevJudgeResolver::judge() picks the binding up.
 *
 * @package Cms\Core\Services\Ai\Jev
 * @since   2026-11-24
 */
interface JevJudge
{
    public function judge(JevRequest $request): JevVerdict;
}
