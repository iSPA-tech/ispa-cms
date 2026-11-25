<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Dump;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs an external dump tool from an argv array (never a shell string) and carries the small filesystem helpers
 * the dumpers share. Tests subclass it and override run().
 */
class DumpProcessRunner
{
    /**
     * Run a process and stream its stdout to the callback; stdout is not buffered in memory.
     *
     * @param array<int, string> $argv command and arguments
     * @param array<string, string> $env extra environment variables (merged over the inherited environment)
     * @param (callable(string): void)|null $onChunk receives each stdout chunk
     * @return array{exitCode: int, stderr: string} exit code (-1 on timeout) and the tail of stderr
     */
    public function run(array $argv, array $env = [], ?callable $onChunk = null): array
    {
        $process = new Process($argv, null, $env, null, (float) config('core.backup.process_timeout'));
        $process->disableOutput();
        $stderr = '';

        try {
            $process->run(function (string $type, string $buffer) use (&$stderr, $onChunk): void {
                if ($type === Process::OUT) {
                    if ($onChunk !== null) {
                        $onChunk($buffer);
                    }

                    return;
                }
                $stderr = substr($stderr . $buffer, -4096);
            });
        } catch (ProcessTimedOutException) {
            return ['exitCode' => -1, 'stderr' => 'process timed out'];
        }

        return ['exitCode' => $process->getExitCode() ?? -1, 'stderr' => $stderr];
    }

    /**
     * Ensure a directory exists with mode 0770: the web server user and the code owner (the core upgrade runner) both
     * reach it through the ACLs INSTALL.md sets on storage/. Mode 0700 would set the ACL mask to nothing and lock the
     * other user out; others stay out either way.
     */
    public static function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new \RuntimeException('BACKUP_DUMP_FAILED: cannot create directory');
        }
        @chmod($dir, 0770);
    }

    /**
     * Write a 0600 secret file (credentials) inside the staging directory and return its path.
     */
    public static function writeSecretFile(string $stagingDir, string $prefix, string $content): string
    {
        self::ensureDir($stagingDir);
        $path = tempnam($stagingDir, $prefix);
        if ($path === false) {
            throw new \RuntimeException('BACKUP_DUMP_FAILED: cannot create credential file');
        }
        chmod($path, 0600);
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Remove any password occurrence from text before it is put in an error message.
     */
    public static function redact(string $text, string $password): string
    {
        $text = $password !== '' ? str_replace($password, '***', $text) : $text;

        return substr(trim($text), 0, 300);
    }
}
