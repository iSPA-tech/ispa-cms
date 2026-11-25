<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Jev;

/**
 * The JEV judge in use: the container binding of JevJudge when a module (or a test) bound one, else Core's
 * AiTaskJevJudge.
 *
 * @package Cms\Core\Services\Ai\Jev
 * @since   2026-11-24
 */
final class JevJudgeResolver
{
    public static function judge(): JevJudge
    {
        return app()->bound(JevJudge::class) ? app(JevJudge::class) : app(AiTaskJevJudge::class);
    }
}
