<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Runs an artisan command in a fresh PHP CLI process.
 *
 * nwidart keeps the module list in static properties per PHP process, so
 * caches that depend on the module set (config, route, event) must be built
 * in a new process after a status change. The child process:
 *
 * - uses the PHP CLI binary (config `modules.php_cli` / env `MODULES_PHP_CLI`,
 *   else Symfony's PhpExecutableFinder) and refuses an fpm binary, because
 *   under php-fpm `PHP_BINARY` is the fpm daemon;
 * - runs from base_path() with an argv array, never a shell string;
 * - inherits only PATH, HOME, LANG, LC_ALL and TZ, so `.env` decides the
 *   configuration exactly as during a deploy and no supervisor- or
 *   request-injected variable is baked into `config:cache`;
 * - reports at most 2 KB of stdout and stderr.
 *
 * @package App\Support\Modules
 * @author  iSPA Team
 * @since   2026-11-06
 */
class ArtisanSubprocess
{
    /** Bytes of stdout/stderr kept in the result. */
    public const OUTPUT_LIMIT = 2048;

    /** Inherited environment variables the child keeps. */
    public const KEPT_VARIABLES = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ'];

    /** @var array<string, string> Variables added to every child until changed (cache file targets). */
    private array $extraEnvironment = [];

    /**
     * Set variables the following run() calls add to the child environment (an empty array removes them again).
     *
     * Used by RefreshRuntimeCachesJob to point `APP_ROUTES_CACHE` and friends at a temporary file, so a cache is
     * built beside the live one and renamed into place.
     *
     * @param  array<string, string>  $variables  Name => value.
     * @return void
     */
    public function setExtraEnvironment(array $variables): void
    {
        $this->extraEnvironment = $variables;
    }

    /**
     * Variables the following run() calls add to the child environment.
     *
     * @return array<string, string>
     */
    public function extraEnvironment(): array
    {
        return $this->extraEnvironment;
    }

    /**
     * Run `php artisan <args>` in a clean subprocess.
     *
     * @param  array<int, string>  $args     Artisan arguments, e.g. ['config:cache'].
     * @param  int                 $timeout  Timeout in seconds.
     * @return ProcessResult  Result with output truncated to 2 KB.
     *
     * @throws RuntimeException When no usable PHP CLI binary is found.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function run(array $args, int $timeout): ProcessResult
    {
        $command = [$this->phpBinary(), base_path('artisan'), ...array_map('strval', $args)];

        $result = Process::path(base_path())
            ->env(array_merge($this->environment($this->inheritedEnvironment()), $this->extraEnvironment))
            ->timeout($timeout)
            ->run($command);

        return $this->truncatedResult(
            $result->command(),
            $result->exitCode(),
            self::truncate($result->output()),
            self::truncate($result->errorOutput()),
        );
    }

    /**
     * Resolve the PHP CLI binary for the child process.
     *
     * @return string  Absolute or PATH-relative path of the PHP CLI binary.
     *
     * @throws RuntimeException When no binary is found or it is an fpm binary.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function phpBinary(): string
    {
        // config/modules.php has no php_cli key yet; the env fallback keeps
        // MODULES_PHP_CLI working until the key is added there.
        $configured = config('modules.php_cli') ?: env('MODULES_PHP_CLI');
        $binary = is_string($configured) && $configured !== ''
            ? $configured
            : (new PhpExecutableFinder())->find(false);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('No PHP CLI binary found; set MODULES_PHP_CLI.');
        }

        if (str_contains(strtolower(basename($binary)), 'fpm')) {
            throw new RuntimeException(sprintf('Refusing to run artisan with the fpm binary "%s"; set MODULES_PHP_CLI to the PHP CLI.', basename($binary)));
        }

        return $binary;
    }

    /**
     * Build the child environment map: every inherited variable except the
     * kept ones maps to false, which Symfony Process treats as "unset".
     *
     * @param  array<int|string, mixed>  $inherited  Variables the child would inherit.
     * @return array<string, false>  Environment overrides for the child.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function environment(array $inherited): array
    {
        $map = [];
        foreach (array_keys($inherited) as $name) {
            if (! is_string($name) || $name === '' || in_array($name, self::KEPT_VARIABLES, true)) {
                continue;
            }
            $map[$name] = false;
        }

        return $map;
    }

    /**
     * Variables a child process would inherit from this process.
     *
     * @return array<int|string, mixed>  getenv() + $_ENV + $_SERVER keys.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function inheritedEnvironment(): array
    {
        $getenv = getenv();

        return (is_array($getenv) ? $getenv : []) + $_ENV + $_SERVER;
    }

    /**
     * Cut a string to at most $limit bytes.
     *
     * @param  string  $text   Process output.
     * @param  int     $limit  Byte limit.
     * @return string  The first $limit bytes of $text.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public static function truncate(string $text, int $limit = self::OUTPUT_LIMIT): string
    {
        return strlen($text) <= $limit ? $text : substr($text, 0, $limit);
    }

    /**
     * Wrap the finished process data in an immutable ProcessResult.
     */
    private function truncatedResult(string $command, ?int $exitCode, string $output, string $errorOutput): ProcessResult
    {
        return new class($command, $exitCode, $output, $errorOutput) implements ProcessResult {
            public function __construct(
                private readonly string $command,
                private readonly ?int $exitCode,
                private readonly string $output,
                private readonly string $errorOutput,
            ) {
            }

            public function command()
            {
                return $this->command;
            }

            public function successful()
            {
                return $this->exitCode === 0;
            }

            public function failed()
            {
                return ! $this->successful();
            }

            public function exitCode()
            {
                return $this->exitCode;
            }

            public function output()
            {
                return $this->output;
            }

            public function seeInOutput(string $output)
            {
                return str_contains($this->output, $output);
            }

            public function errorOutput()
            {
                return $this->errorOutput;
            }

            public function seeInErrorOutput(string $output)
            {
                return str_contains($this->errorOutput, $output);
            }

            public function throw(?callable $callback = null)
            {
                if ($this->successful()) {
                    return $this;
                }

                $exception = new ProcessFailedException($this);
                if ($callback) {
                    $callback($this, $exception);
                }

                throw $exception;
            }

            public function throwIf(bool $condition, ?callable $callback = null)
            {
                return $condition ? $this->throw($callback) : $this;
            }
        };
    }
}
