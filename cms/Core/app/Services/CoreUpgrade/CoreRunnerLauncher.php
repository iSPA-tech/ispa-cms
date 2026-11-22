<?php

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use Closure;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The Laravel scheduler's share of the upgrade runner (same-user hosting, where `schedule:run` runs as the code
 * owner). Each minute it does only the cheap pre-boot part in process ({@see CoreRunnerTick::preBoot()}: can this
 * user run it, heartbeat, is a request waiting); when a request waits it starts `php <base>/ispa upgrade
 * --run-requested` as a detached process and returns at once, so a long upgrade never holds up `schedule:run`.
 * The runner lock in that process guarantees one runner at a time.
 */
final class CoreRunnerLauncher
{
    private readonly string $base;

    /** @var Closure(list<string>): void */
    private readonly Closure $spawn;

    /**
     * @param (callable(list<string>): void)|null $spawn Starts the command detached (default: a background shell job with its output discarded).
     */
    public function __construct(?string $base = null, ?callable $spawn = null)
    {
        $this->base = rtrim($base ?? base_path(), '/\\');
        $this->spawn = $spawn !== null ? Closure::fromCallable($spawn) : static function (array $command): void {
            exec(implode(' ', array_map('escapeshellarg', $command)).' >/dev/null 2>&1 &');
        };
    }

    /**
     * @return string The pre-boot answer: `cannot_run`, `idle`, or `run` (the runner process was started).
     */
    public function tick(?int $now = null): string
    {
        $answer = (new CoreRunnerTick($this->base))->preBoot($now);
        if ($answer === 'run') {
            ($this->spawn)([(new PhpExecutableFinder())->find(false) ?: PHP_BINARY, $this->base.'/ispa', 'upgrade', '--run-requested']);
        }

        return $answer;
    }
}
