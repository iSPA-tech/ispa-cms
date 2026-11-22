<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

/**
 * Static scan of a module tree for code a human reviewer must look at before a package is approved.
 *
 * The scan flags; it never refuses (refusals are {@see PackageAudit}). It only reads files: PHP is split with
 * `token_get_all()` (never included or evaluated), scripts are matched with regular expressions, and symlinks are not
 * followed. Each finding names its category, rule, file (relative to the module folder), line and the trimmed line.
 *
 * Categories and rules:
 *  - shell: exec, shell_exec, system, passthru, proc_open, popen, pcntl_exec, backtick operators, and the Symfony or
 *    Laravel `Process` class (`new Process`, `Process::...`);
 *  - file_write: raw filesystem writes by path (file_put_contents, fopen in a write mode, unlink, rename, copy, mkdir,
 *    chmod, symlink, ...) and the `File::` facade's writing methods, unless the target path starts with `__DIR__`,
 *    `__FILE__`, `dirname(__DIR__...)` or `module_path(` (inside the module). `php://` streams (output, temp, memory)
 *    and the disk-scoped `Storage::` facade are not flagged;
 *  - network: curl, sockets, raw `mail()`, the `Http::` facade, Guzzle's Client/Pool/HandlerStack and SoapClient, a
 *    URL literal opened with a file function, and in scripts `fetch`/axios on an absolute URL, `new XMLHttpRequest`,
 *    `new WebSocket`, `sendBeacon`;
 *  - obfuscation: eval, assert, create_function, base64_decode, str_rot13, gzinflate, gzuncompress, gzdecode,
 *    convert_uudecode, a `preg_replace` pattern with the `/e` modifier, a long base64-looking string literal, a run of
 *    `\x` escapes, and in scripts `eval(`, `new Function(` and long base64-looking literals.
 *
 * Limits (review still applies): calls built at run time (variable function names, `call_user_func('exec', ...)`,
 * strings decoded later) are not seen; Blade `@php` blocks and `{{ }}` echoes are not PHP tokens and are not scanned;
 * "inside the module" is judged only from the first argument's opening tokens.
 */
final class PackageStaticScan
{
    public const SHELL = 'shell';
    public const FILE_WRITE = 'file_write';
    public const NETWORK = 'network';
    public const OBFUSCATION = 'obfuscation';

    /** PHP source extensions scanned with the tokenizer. */
    private const PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php7', 'php8', 'phps', 'inc'];

    /** Script extensions scanned with regular expressions. */
    private const SCRIPT_EXTENSIONS = ['js', 'mjs', 'cjs', 'ts', 'vue'];

    /** Longest excerpt of the flagged line, in characters. */
    private const EXCERPT_CHARS = 160;

    /** Shortest string literal judged as an encoded payload when it looks like base64. */
    private const ENCODED_MIN_CHARS = 200;

    /** @var array<string, string> function name => category */
    private const FUNCTIONS = [
        'exec' => self::SHELL, 'shell_exec' => self::SHELL, 'system' => self::SHELL, 'passthru' => self::SHELL,
        'proc_open' => self::SHELL, 'popen' => self::SHELL, 'pcntl_exec' => self::SHELL,
        'curl_init' => self::NETWORK, 'curl_exec' => self::NETWORK, 'curl_multi_exec' => self::NETWORK,
        'fsockopen' => self::NETWORK, 'pfsockopen' => self::NETWORK, 'stream_socket_client' => self::NETWORK,
        'socket_create' => self::NETWORK, 'socket_connect' => self::NETWORK, 'ftp_connect' => self::NETWORK,
        'ftp_ssl_connect' => self::NETWORK, 'mail' => self::NETWORK,
        'assert' => self::OBFUSCATION, 'create_function' => self::OBFUSCATION, 'base64_decode' => self::OBFUSCATION,
        'str_rot13' => self::OBFUSCATION, 'gzinflate' => self::OBFUSCATION, 'gzuncompress' => self::OBFUSCATION,
        'gzdecode' => self::OBFUSCATION, 'convert_uudecode' => self::OBFUSCATION,
    ];

    /**
     * Raw filesystem functions that write, move or delete by path; flagged unless the path is inside the module.
     * Handle writes (fwrite, fputcsv, ...) are not listed: the fopen() in a write mode that made the handle is.
     */
    private const WRITE_FUNCTIONS = [
        'file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'chgrp', 'lchown',
        'lchgrp', 'symlink', 'link', 'move_uploaded_file', 'tempnam',
    ];

    /** Guzzle classes that send requests (exceptions, PSR-7 streams and the like do not). */
    private const GUZZLE_SENDERS = ['guzzlehttp\\client', 'guzzlehttp\\pool', 'guzzlehttp\\handlerstack'];

    /** Functions that open a path; a URL literal makes them a network call. */
    private const OPEN_FUNCTIONS = ['file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'simplexml_load_file'];

    /** `File::` facade methods that write, move or delete. */
    private const FILE_FACADE_WRITES = [
        'put', 'append', 'prepend', 'replace', 'replaceinfile', 'delete', 'move', 'copy', 'link', 'relativelink', 'chmod',
        'makedirectory', 'ensuredirectoryexists', 'movedirectory', 'copydirectory', 'deletedirectory',
        'deletedirectories', 'cleandirectory',
    ];

    /** URL schemes that make an opened path a network call. */
    private const URL_LITERAL = '#^(?:https?|ftps?|ssh2\.\w+|tcp|udp|ssl|tls)://#i';

    /** Script patterns: regex => [category, rule]. */
    private const SCRIPT_PATTERNS = [
        '/\beval\s*\(/' => [self::OBFUSCATION, 'eval'],
        '/\bnew\s+Function\s*\(/' => [self::OBFUSCATION, 'new Function'],
        '/[\'"`][A-Za-z0-9+\/]{200,}={0,2}[\'"`]/' => [self::OBFUSCATION, 'encoded string'],
        '/\bfetch\s*\(\s*[\'"`](?:https?:)?\/\//' => [self::NETWORK, 'fetch'],
        '/\baxios(?:\.\w+)?\s*\(\s*[\'"`](?:https?:)?\/\//' => [self::NETWORK, 'axios'],
        '/\bnew\s+XMLHttpRequest\b/' => [self::NETWORK, 'XMLHttpRequest'],
        '/\bnew\s+WebSocket\s*\(/' => [self::NETWORK, 'WebSocket'],
        '/\bsendBeacon\s*\(/' => [self::NETWORK, 'sendBeacon'],
    ];

    /**
     * Scan every PHP and script file under a module folder.
     *
     * @param string $moduleDir  module source folder or extracted package folder
     * @param bool   $sourceTree skip what the package builder never packages (tests, node_modules, .git*, ...);
     *                           false (an extracted package) scans every file
     *
     * @return list<array{category: string, rule: string, file: string, line: int, excerpt: string}> sorted by file and line
     */
    public function scan(string $moduleDir, bool $sourceTree = false): array
    {
        $moduleDir = rtrim($moduleDir, '/\\');
        $findings = [];
        foreach ($this->files($moduleDir, '', $sourceTree) as $relative) {
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $source = @file_get_contents($moduleDir . '/' . $relative);
            if (!is_string($source)) {
                continue;
            }
            if (in_array($extension, self::PHP_EXTENSIONS, true)) {
                array_push($findings, ...$this->scanPhp($source, $relative));
            } elseif (in_array($extension, self::SCRIPT_EXTENSIONS, true)) {
                array_push($findings, ...$this->scanScript($source, $relative));
            }
        }

        return $findings;
    }

    /**
     * Findings counted per category, every category present (zero when none).
     *
     * @param list<array{category: string}> $findings
     *
     * @return array<string, int>
     */
    public static function summary(array $findings): array
    {
        $out = [self::SHELL => 0, self::FILE_WRITE => 0, self::NETWORK => 0, self::OBFUSCATION => 0];
        foreach ($findings as $finding) {
            $out[$finding['category']] = ($out[$finding['category']] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Scan one PHP source text with the tokenizer.
     *
     * @return list<array{category: string, rule: string, file: string, line: int, excerpt: string}>
     */
    private function scanPhp(string $source, string $file): array
    {
        $lines = explode("\n", $source);
        $tokens = array_values(array_filter(
            $this->withLines(token_get_all($source)),
            static fn (array $t): bool => !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];
        $add = function (string $category, string $rule, int $line) use (&$found, $file, $lines): void {
            $found[$line . ':' . $rule] = $this->finding($category, $rule, $file, $line, $lines);
        };
        $inBacktick = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];
            if ($id === -1 && $text === '`') {
                if (!$inBacktick) {
                    $add(self::SHELL, 'backtick', $line);
                }
                $inBacktick = !$inBacktick;
                continue;
            }
            if ($id === T_EVAL) {
                $add(self::OBFUSCATION, 'eval', $line);
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $this->checkLiteral($text, $line, $add);
                continue;
            }
            if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1][1] ?? null;
            $short = strtolower(ltrim((string) substr($text, (int) strrpos('\\' . $text, '\\')), '\\'));
            if ($id !== T_STRING && in_array(strtolower(ltrim($text, '\\')), self::GUZZLE_SENDERS, true)) {
                $add(self::NETWORK, 'guzzle', $line);
                continue;
            }
            if ($next === '::') {
                $method = strtolower($tokens[$i + 2][1] ?? '');
                if ($method === 'class') {
                    continue;
                }
                if ($short === 'process') {
                    $add(self::SHELL, 'Process', $line);
                } elseif ($short === 'http') {
                    $add(self::NETWORK, 'Http', $line);
                } elseif ($short === 'file' && in_array($method, self::FILE_FACADE_WRITES, true) && ($tokens[$i + 3][1] ?? null) === '(' && !$this->insideModule($tokens, $i + 4)) {
                    $add(self::FILE_WRITE, 'File::' . $tokens[$i + 2][1], $line);
                }
                continue;
            }
            if ($previous !== null && $previous[0] === T_NEW) {
                if ($short === 'process') {
                    $add(self::SHELL, 'Process', $line);
                } elseif ($short === 'soapclient') {
                    $add(self::NETWORK, 'SoapClient', $line);
                }
                continue;
            }
            if ($next !== '(' || ($previous !== null && in_array($previous[1], ['->', '?->', '::', 'function', 'const'], true))) {
                continue;
            }
            $this->checkCall($short, $tokens, $i, $line, $add);
        }

        return array_values($found);
    }

    /**
     * Flag a plain function call by name (the token at $i is the name, $i + 1 is "(").
     *
     * @param list<array{0: int, 1: string, 2: int}>   $tokens significant tokens
     * @param callable(string, string, int): void       $add
     */
    private function checkCall(string $name, array $tokens, int $i, int $line, callable $add): void
    {
        if (isset(self::FUNCTIONS[$name])) {
            $add(self::FUNCTIONS[$name], $name, $line);
        }
        $first = $tokens[$i + 2] ?? null;
        if (in_array($name, self::OPEN_FUNCTIONS, true) && $first !== null && $first[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match(self::URL_LITERAL, $this->unquote($first[1])) === 1) {
            $add(self::NETWORK, $name . ' url', $line);

            return;
        }
        $memoryStream = $first !== null && $first[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with(strtolower($this->unquote($first[1])), 'php://');
        if ($name === 'fopen' && !$memoryStream && !$this->insideModule($tokens, $i + 2) && $this->writeMode($tokens, $i + 2)) {
            $add(self::FILE_WRITE, 'fopen', $line);
        }
        if (in_array($name, self::WRITE_FUNCTIONS, true) && !$this->insideModule($tokens, $i + 2)) {
            $add(self::FILE_WRITE, $name, $line);
        }
        if ($name === 'preg_replace' && $first !== null && $first[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/^(.).*\1([a-zA-Z]*)$/s', $this->unquote($first[1]), $m) === 1 && str_contains($m[2], 'e')) {
            $add(self::OBFUSCATION, 'preg_replace /e', $line);
        }
    }

    /**
     * Flag string literals that look like an encoded payload.
     *
     * @param callable(string, string, int): void $add
     */
    private function checkLiteral(string $literal, int $line, callable $add): void
    {
        $value = $this->unquote($literal);
        if (strlen($value) >= self::ENCODED_MIN_CHARS && preg_match('#^[A-Za-z0-9+/\r\n]+={0,2}$#', $value) === 1) {
            $add(self::OBFUSCATION, 'encoded string', $line);
        } elseif (preg_match('/(?:\\\\x[0-9a-fA-F]{2}){8,}/', $literal) === 1) {
            $add(self::OBFUSCATION, 'hex escapes', $line);
        }
    }

    /**
     * Whether the argument starting at token $start is a path inside the module (`__DIR__`, `__FILE__`,
     * `dirname(__DIR__...)`, `module_path(`).
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private function insideModule(array $tokens, int $start): bool
    {
        $first = $tokens[$start] ?? null;
        if ($first === null) {
            return false;
        }
        if ($first[0] === T_DIR || $first[0] === T_FILE) {
            return true;
        }
        $name = strtolower(ltrim($first[1], '\\'));
        if ($name === 'module_path' && ($tokens[$start + 1][1] ?? null) === '(') {
            return true;
        }

        return $name === 'dirname' && ($tokens[$start + 1][1] ?? null) === '('
            && in_array($tokens[$start + 2][0] ?? null, [T_DIR, T_FILE], true);
    }

    /**
     * Whether the fopen() call whose first argument starts at $start opens for writing: the second argument is a
     * literal mode containing w, a, x, c or +, or is not a literal (unknown, so flagged).
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private function writeMode(array $tokens, int $start): bool
    {
        $depth = 0;
        $count = count($tokens);
        for ($j = $start; $j < $count; $j++) {
            $text = $tokens[$j][1];
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                if ($depth === 0) {
                    return false;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $mode = $tokens[$j + 1] ?? null;
                if ($mode === null || $mode[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    return true;
                }

                return preg_match('/[waxc+]/', $this->unquote($mode[1])) === 1;
            }
        }

        return false;
    }

    /**
     * Scan one script (JS, TS, Vue) with regular expressions.
     *
     * @return list<array{category: string, rule: string, file: string, line: int, excerpt: string}>
     */
    private function scanScript(string $source, string $file): array
    {
        $lines = explode("\n", $source);
        $found = [];
        foreach (self::SCRIPT_PATTERNS as $pattern => [$category, $rule]) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($matches[0] as [, $offset]) {
                $line = substr_count($source, "\n", 0, $offset) + 1;
                $found[$line . ':' . $rule] = $this->finding($category, $rule, $file, $line, $lines);
            }
        }
        ksort($found, SORT_NATURAL);

        return array_values($found);
    }

    /**
     * One finding record.
     *
     * @param list<string> $lines source lines
     *
     * @return array{category: string, rule: string, file: string, line: int, excerpt: string}
     */
    private function finding(string $category, string $rule, string $file, int $line, array $lines): array
    {
        $excerpt = trim((string) ($lines[$line - 1] ?? ''));

        return ['category' => $category, 'rule' => $rule, 'file' => $file, 'line' => $line, 'excerpt' => mb_strimwidth($excerpt, 0, self::EXCERPT_CHARS, '…', 'UTF-8')];
    }

    /**
     * Normalise tokens to [id, text, line]; single-character tokens get id -1 and the current line.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $raw
     *
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private function withLines(array $raw): array
    {
        $out = [];
        $line = 1;
        foreach ($raw as $token) {
            if (is_array($token)) {
                $line = $token[2];
                $out[] = [$token[0], $token[1], $line];
                $line += substr_count($token[1], "\n");
            } else {
                $out[] = [-1, $token, $line];
            }
        }

        return $out;
    }

    /**
     * The value of a quoted PHP string literal (escape sequences are left as written).
     */
    private function unquote(string $literal): string
    {
        return strlen($literal) >= 2 ? substr($literal, 1, -1) : $literal;
    }

    /**
     * Files to scan (relative, sorted), never following symlinks.
     *
     * @return list<string>
     */
    private function files(string $dir, string $prefix, bool $sourceTree): array
    {
        $names = array_values(array_diff(scandir($dir . ($prefix === '' ? '' : '/' . $prefix)) ?: [], ['.', '..']));
        sort($names, SORT_STRING);
        $out = [];
        foreach ($names as $name) {
            if ($sourceTree && PackageAudit::excludedFromBuild($name)) {
                continue;
            }
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            $full = $dir . '/' . $relative;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                array_push($out, ...$this->files($dir, $relative, $sourceTree));
            } elseif (is_file($full)) {
                $out[] = $relative;
            }
        }

        return $out;
    }
}
