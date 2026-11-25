<?php

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use Closure;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts the upgrade work in the background, each start returning at once:
 * - from the admin request itself, so an upgrade asked from the admin starts without cron;
 * - from the Laravel scheduler every minute, as the fallback.
 * While an upgrade request waits it starts `cms:core-upgrade-backup` (the safety backup is taken by the web user, who
 * owns storage/backups). When this user may run the runner, it
 * starts `php <base>/ispa upgrade --run-requested` as a detached process after the cheap pre-boot check
 * ({@see CoreRunnerTick::preBoot()}). The runner lock in that process keeps one runner at a time.
 */
final class CoreRunnerLauncher
{
    private readonly string $base;

    /** @var Closure(list<string>): void */
    private readonly Closure $spawn;

    /**
     * @param (callable(list<string>): void)|null $spawn Starts the command detached (default: a background shell job with its output discarded; nothing in unit tests or where `exec` is disabled).
     */
    public function __construct(?string $base = null, ?callable $spawn = null)
    {
        $this->base = rtrim($base ?? base_path(), '/\\');
        $this->spawn = $spawn !== null ? Closure::fromCallable($spawn) : static function (array $command): void {
            if (app()->runningUnitTests() || ! self::canExec()) {
                return;
            }
            exec(implode(' ', array_map('escapeshellarg', $command)).' >/dev/null 2>&1 &');
        };
    }

    /**
     * One scheduler minute: the backup of a waiting upgrade, then the runner when this user may run it.
     *
     * @return string The pre-boot answer: `cannot_run`, `idle`, or `run` (the runner process was started).
     */
    public function tick(?int $now = null): string
    {
        $this->startBackup();

        return $this->startRunner($now);
    }

    /**
     * Start `cms:core-upgrade-backup` when an upgrade request waits. It takes the safety backup as this (web) user,
     * who owns storage/backups, and then starts the runner itself, so an upgrade asked from the admin needs no cron.
     */
    public function startBackup(): void
    {
        if ((((new CoreRunnerTick($this->base))->readRequest())['action'] ?? null) === 'upgrade' && is_file($this->base.'/artisan')) {
            ($this->spawn)([self::php(), $this->base.'/artisan', \Cms\Core\Console\Commands\CoreUpgradeBackupCommand::NAME]);
        }
    }

    /**
     * Start the runner (`ispa upgrade --run-requested`) when a request waits and this user may run it: the code
     * owner, which on simple hosting is the web user itself.
     *
     * @return string `cannot_run`, `idle` or `run`.
     */
    public function startRunner(?int $now = null): string
    {
        $answer = (new CoreRunnerTick($this->base))->preBoot($now);
        if ($answer === 'run') {
            ($this->spawn)([self::php(), $this->base.'/ispa', 'upgrade', '--run-requested']);
        }

        return $answer;
    }

    /** The PHP command line binary (under PHP-FPM, PHP_BINARY is the FPM daemon, so it is never used then). */
    private static function php(): string
    {
        $found = (new PhpExecutableFinder())->find(false);

        return is_string($found) && $found !== '' ? $found : 'php';
    }

    private static function canExec(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }
}
