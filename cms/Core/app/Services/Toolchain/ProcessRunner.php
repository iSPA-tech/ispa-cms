<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Toolchain;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the node tools and the dev servers behind `ispa start` and `ispa build`. Bound in the container so tests
 * replace it with a fake that records the commands.
 */
class ProcessRunner
{
    /** Exit code reported when a binary cannot be started (the shell convention for "command not found"). */
    public const NOT_FOUND = 127;

    /**
     * Run one command, streaming its output, and return its exit code.
     *
     * @param array<int, string>        $cmd   Argv array, never a shell string
     * @param array<string, string|false> $env Extra environment (merged over the current one; false unsets)
     * @param bool                      $quiet Drop the output
     */
    public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
    {
        try {
            $process = new Process($cmd, $cwd, $env, null, null);
            $process->run($quiet ? null : static function (string $type, string $buffer): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            });

            return $process->getExitCode() ?? self::NOT_FOUND;
        } catch (Throwable) {
            return self::NOT_FOUND;
        }
    }

    /**
     * Run several long-lived commands together. Every output line gets a `[name]` prefix. Ctrl+C or the first command
     * that exits with a non-zero code stops all of them.
     *
     * @param array<string, array{cmd: array<int, string>, cwd: ?string, env: array<string, string|false>}> $jobs
     * @return int First non-zero exit code, or 0 when every command finished cleanly
     */
    public function parallel(array $jobs): int
    {
        $interrupted = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use (&$interrupted): void {
                $interrupted = true;
            });
            pcntl_signal(SIGTERM, static function () use (&$interrupted): void {
                $interrupted = true;
            });
        }

        /** @var array<string, Process> $processes */
        $processes = [];
        $buffers = [];
        $result = 0;

        try {
            foreach ($jobs as $name => $job) {
                $process = new Process($job['cmd'], $job['cwd'] ?? null, $job['env'] ?? [], null, null);
                $process->start();
                $processes[$name] = $process;
                $buffers[$name] = ['out' => '', 'err' => ''];
            }

            while ($processes !== []) {
                foreach ($processes as $name => $process) {
                    $this->emit($name, $process->getIncrementalOutput(), $buffers[$name]['out'], false);
                    $this->emit($name, $process->getIncrementalErrorOutput(), $buffers[$name]['err'], true);

                    if (! $process->isRunning()) {
                        $this->emit($name, $process->getIncrementalOutput(), $buffers[$name]['out'], false);
                        $this->emit($name, $process->getIncrementalErrorOutput(), $buffers[$name]['err'], true);
                        $this->flush($name, $buffers[$name]);
                        $code = $process->getExitCode() ?? self::NOT_FOUND;
                        unset($processes[$name]);
                        if ($code !== 0 && $result === 0) {
                            $result = $code;
                            $this->line($name, "exited with code {$code}; stopping the others", true);
                        }
                    }
                }

                if ($interrupted && $result === 0) {
                    $result = 130;
                }
                if ($result !== 0) {
                    break;
                }

                usleep(50_000);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(5);
                }
            }
        }

        return $result;
    }

    /**
     * Print the complete lines of a chunk and keep the unfinished tail in the buffer.
     */
    private function emit(string $name, string $chunk, string &$buffer, bool $error): void
    {
        if ($chunk === '') {
            return;
        }

        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $this->line($name, rtrim(substr($buffer, 0, $pos), "\r"), $error);
            $buffer = substr($buffer, $pos + 1);
        }
    }

    /**
     * Print what is left in the buffers of a finished process.
     *
     * @param array{out: string, err: string} $buffers
     */
    private function flush(string $name, array $buffers): void
    {
        foreach (['out' => false, 'err' => true] as $key => $error) {
            if ($buffers[$key] !== '') {
                $this->line($name, $buffers[$key], $error);
            }
        }
    }

    private function line(string $name, string $text, bool $error): void
    {
        fwrite($error ? STDERR : STDOUT, "[{$name}] {$text}\n");
    }
}
